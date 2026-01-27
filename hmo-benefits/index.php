<?php
// benefits-hmo-management.php - Employee Benefits & HMO Management
ob_start();
include '../config/db.php';
include '../includes/sidebar.php';

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

$currentTheme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

// Create mandatory_benefits table if it doesn't exist - UPDATED: Removed contribution columns
$createTableSQL = "
CREATE TABLE IF NOT EXISTS `mandatory_benefits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `benefit_name` varchar(200) NOT NULL,
  `agency` enum('SSS','PhilHealth','Pag-IBIG','BIR','DOLE','Others') NOT NULL,
  `description` text NOT NULL,
  `coverage` text DEFAULT NULL,
  `eligibility` text DEFAULT NULL,
  `required_documents` text DEFAULT NULL,
  `benefit_type` enum('Social Security','Health Insurance','Housing Loan','Retirement','Life Insurance','Disability','Maternity','Sickness','Death','Emergency Loan','Salary Loan','Paternity','Solo Parent','Other') DEFAULT 'Other',
  `status` enum('Active','Inactive','Updated') DEFAULT 'Active',
  `effective_date` date DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

try {
    $pdo->exec($createTableSQL);

    // Check if table is empty and insert sample data - UPDATED: Removed contribution values
    $checkCount = $pdo->query("SELECT COUNT(*) as count FROM mandatory_benefits")->fetch(PDO::FETCH_ASSOC);

    if ($checkCount['count'] == 0) {
        $insertSQL = "
        INSERT INTO `mandatory_benefits` (`benefit_name`, `agency`, `description`, `coverage`, `eligibility`, `required_documents`, `benefit_type`, `status`, `effective_date`) VALUES
        ('SSS Social Security', 'SSS', 'Social Security System provides social protection to workers in the private sector.', 'Retirement, disability, death, maternity, sickness, and funeral benefits', 'All private sector employees earning at least PHP 1,000 monthly', 'SSS Form E-1, Birth Certificate, Valid ID', 'Social Security', 'Active', '2024-01-01'),
        ('PhilHealth Health Insurance', 'PhilHealth', 'Philippine Health Insurance Corporation provides health insurance coverage to all Filipino citizens.', 'In-patient and out-patient services, maternity, and emergency care', 'All Filipino citizens and legal residents', 'PhilHealth Member Data Record (MDR), Birth Certificate', 'Health Insurance', 'Active', '2024-01-01'),
        ('Pag-IBIG Housing Loan', 'Pag-IBIG', 'Pag-IBIG Fund provides housing loans to members.', 'Housing loans with low interest rates for members', 'Pag-IBIG members with at least 24 monthly contributions', 'Pag-IBIG Membership ID, Proof of Income, Valid ID', 'Housing Loan', 'Active', '2024-01-01'),
        ('13th Month Pay', 'DOLE', 'Mandatory bonus equivalent to one month''s salary given to employees.', 'Equivalent to one month''s basic salary', 'All rank-and-file employees regardless of employment status', 'Payslips, Employment Contract', 'Retirement', 'Active', '2024-01-01'),
        ('SSS Maternity Benefit', 'SSS', 'Maternity leave benefits for female members.', '105 days paid maternity leave for normal delivery', 'Female SSS members with at least 3 monthly contributions', 'SSS Maternity Notification Form, Medical Certificate', 'Maternity', 'Active', '2024-01-01'),
        ('SSS Sickness Benefit', 'SSS', 'Daily cash allowance for members unable to work due to sickness or injury.', '90 days of sickness benefit per year', 'SSS members with at least 3 monthly contributions', 'SSS Sickness Notification Form, Medical Certificate', 'Sickness', 'Active', '2024-01-01'),
        ('SSS Retirement Benefit', 'SSS', 'Lump sum or monthly pension for retired members.', 'Lump sum or monthly pension based on contributions', 'SSS members who are 60+ and with at least 120 monthly contributions', 'SSS Retirement Claim Application, Birth Certificate', 'Retirement', 'Active', '2024-01-01'),
        ('SSS Death Benefit', 'SSS', 'Cash benefit to beneficiaries of deceased member.', 'Burial grant and monthly pension for beneficiaries', 'Beneficiaries of deceased SSS members', 'Death Certificate, SSS Claim Form, Valid IDs', 'Death', 'Active', '2024-01-01'),
        ('SSS Salary Loan', 'SSS', 'Short-term loan for employed SSS members.', 'One-month salary loan payable in 2 years', 'SSS members with at least 36 monthly contributions', 'SSS Loan Application Form, Valid ID', 'Salary Loan', 'Active', '2024-01-01'),
        ('Paternity Leave', 'DOLE', '7 days leave for married male employees for childbirth.', '7 days paid leave for childbirth of legitimate spouse', 'Married male employees', 'Marriage Certificate, Child Birth Certificate', 'Paternity', 'Active', '2024-01-01'),
        ('Solo Parent Leave', 'DOLE', 'Additional 7 days leave for solo parents.', 'Additional 7 days paid leave per year', 'Registered solo parents', 'Solo Parent ID, Birth Certificate of Child', 'Solo Parent', 'Active', '2024-01-01'),
        ('Service Incentive Leave', 'DOLE', '5 days leave for employees who have rendered at least 1 year of service.', '5 days paid service incentive leave per year', 'Employees with at least 1 year of service', 'Certificate of Employment, Payslips', 'Other', 'Active', '2024-01-01');
        ";
        $pdo->exec($insertSQL);
    }
} catch (PDOException $e) {
    // Table creation error - handle silently
}

// Create reimbursements table if it doesn't exist
$createReimbursementTableSQL = "
CREATE TABLE IF NOT EXISTS `reimbursements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `employee_name` varchar(100) NOT NULL,
  `reimbursement_type` enum('Medical Consultation','Laboratory Tests','Medicines','Hospitalization','Dental','Optical','Other') NOT NULL,
  `amount_requested` decimal(10,2) NOT NULL,
  `amount_approved` decimal(10,2) DEFAULT NULL,
  `expense_date` date NOT NULL,
  `submission_date` date NOT NULL,
  `description` text NOT NULL,
  `receipt_file` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Send Back') DEFAULT 'Pending',
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `send_back_reason` text DEFAULT NULL,
  `processed_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `employee_id` (`employee_id`),
  KEY `status` (`status`),
  KEY `submission_date` (`submission_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

try {
    $pdo->exec($createReimbursementTableSQL);

    // Check if table is empty and insert sample data
    $checkReimbursementCount = $pdo->query("SELECT COUNT(*) as count FROM reimbursements")->fetch(PDO::FETCH_ASSOC);

    if ($checkReimbursementCount['count'] == 0) {
        $insertReimbursementSQL = "
        INSERT INTO `reimbursements` (`employee_id`, `employee_name`, `reimbursement_type`, `amount_requested`, `expense_date`, `submission_date`, `description`, `status`) VALUES
        ('EMP001', 'John Doe', 'Medical Consultation', 1500.00, '2024-01-15', '2024-01-16', 'Consultation with cardiologist for chest pain evaluation', 'Pending'),
        ('EMP002', 'Jane Smith', 'Laboratory Tests', 2200.00, '2024-01-10', '2024-01-12', 'Complete blood count and ECG tests', 'Approved'),
        ('EMP003', 'Mike Johnson', 'Medicines', 850.00, '2024-01-08', '2024-01-09', 'Prescription medicines for hypertension', 'Send Back'),
        ('EMP004', 'Sarah Wilson', 'Dental', 3200.00, '2024-01-05', '2024-01-06', 'Root canal treatment and dental cleaning', 'Rejected');
        ";
        $pdo->exec($insertReimbursementSQL);
    }
} catch (PDOException $e) {
    // Table creation error - handle silently
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_provider'])) {
        addProvider($pdo, $_POST);
    } elseif (isset($_POST['update_provider'])) {
        updateProvider($pdo, $_POST);
    } elseif (isset($_POST['delete_provider'])) {
        deleteProvider($pdo, $_POST['id']);
    } elseif (isset($_POST['enroll_employee'])) {
        enrollEmployee($pdo, $_POST);
    } elseif (isset($_POST['update_enrollment'])) {
        updateEnrollment($pdo, $_POST);
    } elseif (isset($_POST['delete_enrollment'])) {
        deleteEnrollment($pdo, $_POST['id']);
    } elseif (isset($_POST['add_dependent'])) {
        addDependent($pdo, $_POST);
    } elseif (isset($_POST['update_dependent'])) {
        updateDependent($pdo, $_POST);
    } elseif (isset($_POST['delete_dependent'])) {
        deleteDependent($pdo, $_POST['id']);
    } elseif (isset($_POST['add_document'])) {
        addDocument($pdo, $_POST);
    } elseif (isset($_POST['delete_document'])) {
        deleteDocument($pdo, $_POST['id']);
    } elseif (isset($_POST['add_benefit'])) {
        addBenefit($pdo, $_POST);
    } elseif (isset($_POST['update_benefit'])) {
        updateBenefit($pdo, $_POST);
    } elseif (isset($_POST['delete_benefit'])) {
        deleteBenefit($pdo, $_POST['id']);
    } elseif (isset($_POST['add_reimbursement'])) {
        addReimbursement($pdo, $_POST, $_FILES);
    } elseif (isset($_POST['approve_reimbursement'])) {
        updateReimbursementStatus($pdo, $_POST['id'], 'Approved', $_SESSION['user'] ?? 'System');
    } elseif (isset($_POST['reject_reimbursement'])) {
        updateReimbursementStatus($pdo, $_POST['id'], 'Rejected', $_SESSION['user'] ?? 'System', $_POST['rejection_reason']);
    } elseif (isset($_POST['send_back_reimbursement'])) {
        updateReimbursementStatus($pdo, $_POST['id'], 'Send Back', $_SESSION['user'] ?? 'System', $_POST['send_back_reason']);
    }
}

// Provider Management Functions
function addProvider($pdo, $data)
{
    $sql = "INSERT INTO providers (provider_name, provider_type, contact_person, email, phone, address, city, state, zip_code, country, website, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

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
            $data['notes']
        ]);

        $_SESSION['success_message'] = "Provider added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding provider: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function updateProvider($pdo, $data)
{
    $sql = "UPDATE providers SET provider_name=?, provider_type=?, contact_person=?, email=?, phone=?, address=?, city=?, state=?, zip_code=?, country=?, website=?, notes=? WHERE id=?";

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
            $data['id']
        ]);

        $_SESSION['success_message'] = "Provider updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating provider: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function deleteProvider($pdo, $id)
{
    $sql = "DELETE FROM providers WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        $_SESSION['success_message'] = "Provider deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting provider: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Employee Enrollment Functions
function enrollEmployee($pdo, $data)
{
    // Get employee details
    $employee_stmt = $pdo->prepare("SELECT name, department FROM employees WHERE id = ?");
    $employee_stmt->execute([$data['employee_id']]);
    $employee = $employee_stmt->fetch(PDO::FETCH_ASSOC);

    $sql = "INSERT INTO employee_enrollments (employee_id, employee_name, department, provider_id, plan_id, enrollment_date, status, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['employee_id'],
            $employee['name'],
            $employee['department'],
            $data['provider_id'],
            $data['plan_id'],
            $data['enrollment_date'],
            'Active',
            $data['notes']
        ]);

        $_SESSION['success_message'] = "Employee enrolled successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error enrolling employee: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function updateEnrollment($pdo, $data)
{
    $sql = "UPDATE employee_enrollments SET provider_id=?, plan_id=?, enrollment_date=?, notes=? WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['provider_id'],
            $data['plan_id'],
            $data['enrollment_date'],
            $data['notes'],
            $data['id']
        ]);

        $_SESSION['success_message'] = "Enrollment updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating enrollment: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function deleteEnrollment($pdo, $id)
{
    $sql = "DELETE FROM employee_enrollments WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        $_SESSION['success_message'] = "Enrollment deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting enrollment: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Dependent Management Functions
function addDependent($pdo, $data)
{
    $sql = "INSERT INTO dependents (employee_id, name, relationship, age, included_in_plan, additional_cost, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['employee_id'],
            $data['name'],
            $data['relationship'],
            $data['age'],
            $data['included_in_plan'],
            $data['additional_cost'],
            'Active'
        ]);

        $_SESSION['success_message'] = "Dependent added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding dependent: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function updateDependent($pdo, $data)
{
    $sql = "UPDATE dependents SET name=?, relationship=?, age=?, included_in_plan=?, additional_cost=? WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['name'],
            $data['relationship'],
            $data['age'],
            $data['included_in_plan'],
            $data['additional_cost'],
            $data['id']
        ]);

        $_SESSION['success_message'] = "Dependent updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating dependent: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function deleteDependent($pdo, $id)
{
    $sql = "DELETE FROM dependents WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        $_SESSION['success_message'] = "Dependent deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting dependent: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Document Management Functions
function addDocument($pdo, $data)
{
    $sql = "INSERT INTO benefit_documents (document_name, document_type, provider_id, description) 
            VALUES (?, ?, ?, ?)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['document_name'],
            $data['document_type'],
            $data['provider_id'],
            $data['description']
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

// NEW: Benefits Management Functions - UPDATED: Removed contribution parameters
function addBenefit($pdo, $data)
{
    $sql = "INSERT INTO mandatory_benefits (benefit_name, agency, description, coverage, eligibility, required_documents, benefit_type, status, effective_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['benefit_name'],
            $data['agency'],
            $data['description'],
            $data['coverage'],
            $data['eligibility'],
            $data['required_documents'],
            $data['benefit_type'],
            $data['status'],
            $data['effective_date']
        ]);

        $_SESSION['success_message'] = "Benefit added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding benefit: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function updateBenefit($pdo, $data)
{
    $sql = "UPDATE mandatory_benefits SET benefit_name=?, agency=?, description=?, coverage=?, eligibility=?, required_documents=?, benefit_type=?, status=?, effective_date=? WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['benefit_name'],
            $data['agency'],
            $data['description'],
            $data['coverage'],
            $data['eligibility'],
            $data['required_documents'],
            $data['benefit_type'],
            $data['status'],
            $data['effective_date'],
            $data['id']
        ]);

        $_SESSION['success_message'] = "Benefit updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating benefit: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function deleteBenefit($pdo, $id)
{
    $sql = "DELETE FROM mandatory_benefits WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        $_SESSION['success_message'] = "Benefit deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting benefit: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Reimbursement Functions
function addReimbursement($pdo, $data, $files = null)
{
    // Handle file upload
    $receiptFile = null;
    if (isset($files['receipt_file']) && $files['receipt_file']['error'] == 0) {
        $uploadDir = '../uploads/reimbursements/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = time() . '_' . basename($files['receipt_file']['name']);
        $targetFile = $uploadDir . $fileName;

        $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf'];
        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));

        if (in_array($fileType, $allowedTypes) && $files['receipt_file']['size'] <= 5242880) { // 5MB limit
            if (move_uploaded_file($files['receipt_file']['tmp_name'], $targetFile)) {
                $receiptFile = $fileName;
            }
        }
    }

    $sql = "INSERT INTO reimbursements (employee_id, employee_name, reimbursement_type, amount_requested, expense_date, submission_date, description, receipt_file, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['employee_id'],
            $data['employee_name'],
            $data['reimbursement_type'],
            $data['amount_requested'],
            $data['expense_date'],
            $data['submission_date'],
            $data['description'],
            $receiptFile,
            'Pending'
        ]);

        $_SESSION['success_message'] = "Reimbursement request submitted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error submitting reimbursement request: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=reimbursement');
    exit;
}

function updateReimbursementStatus($pdo, $id, $status, $approvedBy, $reason = null)
{
    if ($status === 'Approved') {
        $sql = "UPDATE reimbursements SET status=?, approved_by=?, processed_date=NOW() WHERE id=?";
        $params = [$status, $approvedBy, $id];
    } elseif ($status === 'Rejected') {
        $sql = "UPDATE reimbursements SET status=?, approved_by=?, processed_date=NOW(), rejection_reason=? WHERE id=?";
        $params = [$status, $approvedBy, $reason, $id];
    } elseif ($status === 'Send Back') {
        $sql = "UPDATE reimbursements SET status=?, approved_by=?, processed_date=NOW(), send_back_reason=? WHERE id=?";
        $params = [$status, $approvedBy, $reason, $id];
    } else {
        return;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $_SESSION['success_message'] = "Reimbursement status updated to " . $status . "!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating reimbursement status: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=reimbursement');
    exit;
}

function getReimbursements($pdo, $search = '', $status = '')
{
    $sql = "SELECT * FROM reimbursements WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (employee_id LIKE ? OR employee_name LIKE ? OR reimbursement_type LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if (!empty($status)) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY created_at DESC";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Fetch data for display with search filters
$providers = [];
$plans = [];
$enrollments = [];
$dependents = [];
$documents = [];
$employees = [];
$benefits = [];

// Get search parameters
$search_provider = $_GET['search_provider'] ?? '';
$search_plan = $_GET['search_plan'] ?? '';
$search_enrollment = $_GET['search_enrollment'] ?? '';
$search_dependent = $_GET['search_dependent'] ?? '';
$search_benefit = $_GET['search_benefit'] ?? '';
$search_document = $_GET['search_document'] ?? '';
$filter_agency = $_GET['filter_agency'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';

try {
    // Fetch providers with search
    $provider_sql = "SELECT * FROM providers WHERE status = 'Active'";
    if ($search_provider) {
        $provider_sql .= " AND (provider_name LIKE :search OR contact_person LIKE :search OR email LIKE :search)";
    }
    $provider_sql .= " ORDER BY provider_name";

    $providers_stmt = $pdo->prepare($provider_sql);
    if ($search_provider) {
        $providers_stmt->bindValue(':search', '%' . $search_provider . '%');
    }
    $providers_stmt->execute();
    $providers = $providers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch plans with search
    $plan_sql = "
        SELECT p.*, pr.provider_name 
        FROM plans p 
        LEFT JOIN providers pr ON p.provider_id = pr.id 
        WHERE p.status = 'Active' 
    ";
    if ($search_plan) {
        $plan_sql .= " AND (p.plan_name LIKE :search OR pr.provider_name LIKE :search)";
    }
    $plan_sql .= " ORDER BY pr.provider_name, p.plan_name";

    $plans_stmt = $pdo->prepare($plan_sql);
    if ($search_plan) {
        $plans_stmt->bindValue(':search', '%' . $search_plan . '%');
    }
    $plans_stmt->execute();
    $plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch enrollments with search
    $enrollment_sql = "
        SELECT ee.*, e.name as employee_name, e.department, pr.provider_name, pl.plan_name 
        FROM employee_enrollments ee 
        LEFT JOIN employees e ON ee.employee_id = e.id 
        LEFT JOIN providers pr ON ee.provider_id = pr.id 
        LEFT JOIN plans pl ON ee.plan_id = pl.id 
    ";
    if ($search_enrollment) {
        $enrollment_sql .= " WHERE (e.name LIKE :search OR e.department LIKE :search OR pr.provider_name LIKE :search)";
    }
    $enrollment_sql .= " ORDER BY e.name";

    $enrollments_stmt = $pdo->prepare($enrollment_sql);
    if ($search_enrollment) {
        $enrollments_stmt->bindValue(':search', '%' . $search_enrollment . '%');
    }
    $enrollments_stmt->execute();
    $enrollments = $enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch dependents with search
    $dependent_sql = "
        SELECT d.*, e.name as employee_name 
        FROM dependents d 
        LEFT JOIN employees e ON d.employee_id = e.id 
        WHERE d.status = 'Active' 
    ";
    if ($search_dependent) {
        $dependent_sql .= " AND (d.name LIKE :search OR e.name LIKE :search OR d.relationship LIKE :search)";
    }
    $dependent_sql .= " ORDER BY e.name, d.name";

    $dependents_stmt = $pdo->prepare($dependent_sql);
    if ($search_dependent) {
        $dependents_stmt->bindValue(':search', '%' . $search_dependent . '%');
    }
    $dependents_stmt->execute();
    $dependents = $dependents_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch documents with search
    $document_sql = "
        SELECT bd.*, pr.provider_name 
        FROM benefit_documents bd 
        LEFT JOIN providers pr ON bd.provider_id = pr.id 
    ";
    if ($search_document) {
        $document_sql .= " WHERE (bd.document_name LIKE :search OR bd.document_type LIKE :search OR pr.provider_name LIKE :search)";
    }
    $document_sql .= " ORDER BY bd.document_type, bd.document_name";

    $documents_stmt = $pdo->prepare($document_sql);
    if ($search_document) {
        $documents_stmt->bindValue(':search', '%' . $search_document . '%');
    }
    $documents_stmt->execute();
    $documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch active employees
    $employees_stmt = $pdo->query("SELECT id, name, department FROM employees WHERE status = 'Active' ORDER BY name");
    $employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch reimbursements (for JavaScript access)
    $reimbursements = getReimbursements($pdo);

    // Fetch mandatory benefits with search and filter
    $benefit_sql = "SELECT * FROM mandatory_benefits WHERE 1=1";
    if ($search_benefit) {
        $benefit_sql .= " AND (benefit_name LIKE :search OR description LIKE :search)";
    }
    if ($filter_agency) {
        $benefit_sql .= " AND agency = :agency";
    }
    if ($filter_status) {
        $benefit_sql .= " AND status = :status";
    }
    $benefit_sql .= " ORDER BY agency, benefit_name";

    $benefits_stmt = $pdo->prepare($benefit_sql);
    if ($search_benefit) {
        $benefits_stmt->bindValue(':search', '%' . $search_benefit . '%');
    }
    if ($filter_agency) {
        $benefits_stmt->bindValue(':agency', $filter_agency);
    }
    if ($filter_status) {
        $benefits_stmt->bindValue(':status', $filter_status);
    }
    $benefits_stmt->execute();
    $benefits = $benefits_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get benefit statistics
    $benefit_stats_stmt = $pdo->query("
        SELECT 
            agency,
            COUNT(*) as count
        FROM mandatory_benefits 
        WHERE status = 'Active' 
        GROUP BY agency
    ");
    $benefit_stats = $benefit_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle errors silently
}

// Get statistics for dashboard
$stats = [
    'total_providers' => count($providers),
    'total_plans' => count($plans),
    'total_enrollments' => count($enrollments),
    'total_dependents' => count($dependents),
    'total_documents' => count($documents),
    'total_benefits' => count($benefits),
    'total_reimbursements' => count($reimbursements),
    'pending_reimbursements' => count(array_filter($reimbursements, function ($r) {
        return $r['status'] === 'Pending';
    })),
    'approved_reimbursements' => count(array_filter($reimbursements, function ($r) {
        return $r['status'] === 'Approved';
    })),
    'send_back_reimbursements' => count(array_filter($reimbursements, function ($r) {
        return $r['status'] === 'Send Back';
    }))
];

// Get provider counts
$provider_counts = [];
foreach ($providers as $provider) {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM employee_enrollments WHERE provider_id = ?");
    $count_stmt->execute([$provider['id']]);
    $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $provider_counts[$provider['provider_name']] = $count;
}

// Get dependent counts by provider
$dependent_counts = [];
foreach ($providers as $provider) {
    $count_stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM dependents d 
        JOIN employee_enrollments ee ON d.employee_id = ee.employee_id 
        WHERE ee.provider_id = ? AND d.status = 'Active'
    ");
    $count_stmt->execute([$provider['id']]);
    $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $dependent_counts[$provider['provider_name']] = $count;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benefits & HMO Management | HR System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            transition: background 0.3s, color 0.3s;
            line-height: 1.4;
            overflow-x: hidden;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        .theme-toggle-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .theme-toggle-btn {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 20px;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            box-shadow: var(--shadow);
        }

        body.dark-mode .theme-toggle-btn {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }

        .theme-toggle-btn:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }

        body.dark-mode .theme-toggle-btn:hover {
            background: #4a5568;
        }

        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            width: 100%;
        }

        body.dark-mode .main-content {
            background-color: var(--dark-bg);
        }

        .content-area {
            width: 100%;
            min-height: 100%;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        body.dark-mode .content-area {
            background: var(--dark-card);
        }

        .page-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        body.dark-mode .page-header {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
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

        body.dark-mode .page-subtitle {
            color: #a0aec0;
        }

        .nav-container {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        body.dark-mode .nav-container {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
        }

        .nav-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .nav-link {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s;
        }

        .nav-link:hover {
            color: #2e59d9;
        }

        .portal-link-container {
            display: flex;
            align-items: center;
        }

        .portal-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--success-color);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            box-shadow: var(--shadow);
        }

        .portal-link:hover {
            background: #17a673;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(28, 200, 138, 0.3);
        }

        body.dark-mode .portal-link {
            background: #1a875f;
        }

        body.dark-mode .portal-link:hover {
            background: #17a673;
        }

        /* Stats Cards */
        .stats-container {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }

        body.dark-mode .stat-card {
            background: var(--dark-card);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }

        .stat-card.warning {
            border-left-color: var(--warning-color);
        }

        .stat-card.success {
            border-left-color: var(--success-color);
        }

        .stat-card.info {
            border-left-color: var(--info-color);
        }

        .stat-card.purple {
            border-left-color: var(--purple-color);
        }

        .stat-card.danger {
            border-left-color: var(--danger-color);
        }

        .stat-card.orange {
            border-left-color: var(--orange-color);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        body.dark-mode .stat-label {
            color: #a0aec0;
        }

        /* Forms */
        .form-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
        }

        body.dark-mode .form-container {
            background: var(--dark-card);
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

        body.dark-mode .form-input,
        body.dark-mode .form-select,
        body.dark-mode .form-textarea {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
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

        .btn-purple {
            background: var(--purple-color);
            color: white;
        }

        .btn-purple:hover {
            background: #5a3596;
            transform: translateY(-1px);
        }

        .btn-orange {
            background: var(--orange-color);
            color: white;
        }

        .btn-orange:hover {
            background: #e06c00;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Search and Filter Section */
        .search-filter-section {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        body.dark-mode .search-filter-section {
            background: var(--dark-card);
        }

        .search-box {
            flex: 1;
            min-width: 250px;
        }

        .filter-box {
            min-width: 200px;
        }

        .search-actions {
            display: flex;
            gap: 0.5rem;
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

        body.dark-mode .data-table {
            background: #2d3748;
        }

        .data-table th {
            background: #f8f9fc;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #4e73df;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .data-table th {
            background: #2d3748;
            color: #63b3ed;
            border-bottom: 1px solid #4a5568;
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .data-table td {
            border-bottom: 1px solid #4a5568;
        }

        .data-table tr:hover {
            background: #f8f9fc;
        }

        body.dark-mode .data-table tr:hover {
            background: #2d3748;
        }

        .amount {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-align: right;
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

        body.dark-mode .status-active {
            background: #22543d;
            color: #9ae6b4;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-inactive {
            background: #744210;
            color: #fbd38d;
        }

        .status-updated {
            background: #cce5ff;
            color: #004085;
        }

        body.dark-mode .status-updated {
            background: #2c5282;
            color: #bee3f8;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        body.dark-mode .status-pending {
            background: #744210;
            color: #fbd38d;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        body.dark-mode .status-approved {
            background: #22543d;
            color: #9ae6b4;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-rejected {
            background: #742a2a;
            color: #feb2b2;
        }

        .status-send-back {
            background: #fff3cd;
            color: #856404;
        }

        body.dark-mode .status-send-back {
            background: #744210;
            color: #fbd38d;
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

        body.dark-mode .alert-success {
            background: #22543d;
            color: #9ae6b4;
        }

        .alert-error {
            background: #f8d7da;
            border-color: var(--danger-color);
            color: #721c24;
        }

        body.dark-mode .alert-error {
            background: #744210;
            color: #fbd38d;
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

        body.dark-mode .tabs {
            border-bottom: 1px solid #4a5568;
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

        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }

            .main-content {
                padding: 0;
            }

            .content-area {
                box-shadow: none;
                border-radius: 0;
            }

            .page-header,
            .nav-container,
            .form-container,
            .theme-toggle-container {
                display: none;
            }

            .stats-container {
                padding: 1rem;
                grid-template-columns: repeat(4, 1fr);
            }

            .stat-card {
                box-shadow: none;
                border: 1px solid #e3e6f0;
            }

            .data-table {
                box-shadow: none;
                border: 1px solid #e3e6f0;
            }

            .tabs-container {
                padding: 0;
            }

            .tabs {
                display: none;
            }

            .tab-content {
                display: block !important;
            }

            .actions-cell {
                display: none;
            }

            body,
            body.dark-mode {
                background: white;
                color: black;
            }

            .stat-label {
                color: #6c757d;
            }
        }

        /* Print Report Button */
        .print-btn {
            background: var(--info-color);
            color: white;
        }

        .print-btn:hover {
            background: #2c9faf;
            transform: translateY(-1px);
        }

        @media(max-width:768px) {
            .main-content {
                padding: 1rem;
            }

            .nav-container {
                flex-direction: column;
                align-items: flex-start;
            }

            .portal-link-container {
                align-self: stretch;
            }

            .portal-link {
                width: 100%;
                justify-content: center;
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

            .search-filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box,
            .filter-box {
                min-width: auto;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                border-bottom: 1px solid #e3e6f0;
                border-left: 3px solid transparent;
            }

            body.dark-mode .tab {
                border-bottom: 1px solid #4a5568;
            }

            .tab.active {
                border-left-color: var(--primary-color);
                border-bottom-color: #e3e6f0;
            }

            body.dark-mode .tab.active {
                border-bottom-color: #4a5568;
            }
        }

        @media(max-width:480px) {
            .main-content {
                padding: 0.8rem;
            }

            .form-container {
                margin: 1rem;
                padding: 1rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .table-container {
                padding: 0 1rem 1rem;
            }

            .search-filter-section {
                margin: 1rem;
                padding: 1rem;
            }

            .tabs-container {
                padding: 1rem;
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease-out;
        }

        body.dark-mode .modal-content {
            background-color: var(--dark-card);
            color: var(--text-light);
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }

        body.dark-mode .modal-header {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
            padding: 0.25rem;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .close:hover {
            background: #e9ecef;
            color: #495057;
        }

        body.dark-mode .close:hover {
            background: #4a5568;
            color: #a0aec0;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e3e6f0;
            background: #f8f9fc;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        body.dark-mode .modal-footer {
            background: #2d3748;
            border-top: 1px solid #4a5568;
        }

        /* Responsive Modal */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 10px;
                max-height: 95vh;
            }

            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }

            .modal-title {
                font-size: 1.1rem;
            }
        }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <!-- Theme Toggle -->
    <div class="theme-toggle-container no-print">
        <a href="?toggle_theme=true" class="theme-toggle-btn">
            <?php if ($currentTheme === 'dark'): ?>
                <i class="fas fa-sun"></i> Light Mode
            <?php else: ?>
                <i class="fas fa-moon"></i> Dark Mode
            <?php endif; ?>
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header no-print">
                <h1 class="page-title">
                    <i class="fas fa-heartbeat"></i>
                    Benefits & HMO Management
                </h1>
                <p class="page-subtitle">Manage employee benefits, HMO providers, plans, and enrollments</p>
            </div>

            <!-- Navigation with Portal Link -->
            <div class="nav-container no-print">
                <nav class="nav-breadcrumb">
                    <a href="../hr-dashboard/index.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Benefits & HMO Management</span>
                </nav>

                <!-- Go to Portal Button -->
                <div class="portal-link-container">
                    <a href="../provider/provider-login-portal.php" class="portal-link" target="_blank">
                        <i class="fas fa-external-link-alt"></i>
                        Go to Provider Portal
                    </a>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success no-print">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error no-print">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics Cards - UPDATED: Changed "DOLE Benefits" to "Benefits" -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_providers']; ?></div>
                    <div class="stat-label">Providers</div>
                    <i class="fas fa-building" style="float: right; color: #4e73df; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $stats['total_plans']; ?></div>
                    <div class="stat-label">Plans</div>
                    <i class="fas fa-file-medical" style="float: right; color: #1cc88a; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo $stats['total_enrollments']; ?></div>
                    <div class="stat-label">Enrollments</div>
                    <i class="fas fa-users" style="float: right; color: #36b9cc; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card purple">
                    <div class="stat-value"><?php echo $stats['total_dependents']; ?></div>
                    <div class="stat-label">Dependents</div>
                    <i class="fas fa-user-friends" style="float: right; color: #6f42c1; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card orange">
                    <div class="stat-value"><?php echo $stats['total_benefits']; ?></div>
                    <!-- UPDATED: Changed label from "DOLE Benefits" to "Benefits" -->
                    <div class="stat-label">Benefits</div>
                    <i class="fas fa-file-contract" style="float: right; color: #fd7e14; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo $stats['pending_reimbursements']; ?></div>
                    <div class="stat-label">Pending Reimbursements</div>
                    <i class="fas fa-clock" style="float: right; color: #f39c12; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $stats['approved_reimbursements']; ?></div>
                    <div class="stat-label">Approved Reimbursements</div>
                    <i class="fas fa-check-circle" style="float: right; color: #27ae60; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo $stats['send_back_reimbursements']; ?></div>
                    <div class="stat-label">Send Back Reimbursements</div>
                    <i class="fas fa-undo" style="float: right; color: #17a2b8; font-size: 1.5rem;"></i>
                </div>
            </div>

            <!-- Tabs Navigation - UPDATED: Changed "DOLE Benefits" to "Benefits" -->
            <div class="tabs-container">
                <div class="tabs no-print">
                    <button class="tab active" data-tab="providers">Providers</button>
                    <button class="tab" data-tab="plans">Available Plans</button>
                    <button class="tab" data-tab="enrollments">Enrollments</button>
                    <button class="tab" data-tab="dependents">Dependents</button>
                    <!-- UPDATED: Changed tab name from "DOLE Benefits" to "Benefits" -->
                    <button class="tab" data-tab="benefits">Benefits</button>
                    <button class="tab" data-tab="reimbursement">Reimbursement</button>
                    <button class="tab" data-tab="documents">Documents</button>
                    <button class="tab" data-tab="reports">Reports</button>
                </div>

                <!-- Providers Tab -->
                <div id="providers" class="tab-content active">
                    <!-- Search and Filter for Providers -->
                    <div class="search-filter-section no-print">
                        <form method="GET" action="" class="search-filter-form">
                            <input type="hidden" name="tab" value="providers">
                            <div class="search-box">
                                <label class="form-label">Search Providers</label>
                                <input type="text" name="search_provider" class="form-input"
                                    value="<?php echo htmlspecialchars($search_provider); ?>"
                                    placeholder="Search by provider name, contact person, or email...">
                            </div>
                            <div class="search-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="benefits-hmo-management.php?tab=providers" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- REMOVED: Providers Form Container -->

                    <!-- Providers Table -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Provider Name</th>
                                    <th>Type</th>
                                    <th>Contact Person</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($providers)): ?>
                                    <?php foreach ($providers as $provider): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($provider['provider_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($provider['provider_type']); ?></td>
                                            <td><?php echo htmlspecialchars($provider['contact_person']); ?></td>
                                            <td><?php echo htmlspecialchars($provider['email']); ?></td>
                                            <td><?php echo htmlspecialchars($provider['phone']); ?></td>
                                            <td>
                                                <span class="status-badge status-active">
                                                    <?php echo htmlspecialchars($provider['status']); ?>
                                                </span>
                                            </td>
                                            <td class="actions-cell no-print">
                                                <button type="button" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <a href="?edit_provider=<?php echo $provider['id']; ?>" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this provider?');">
                                                    <input type="hidden" name="id" value="<?php echo $provider['id']; ?>">
                                                    <button type="submit" name="delete_provider" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-building" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No providers found.</p>
                                            <?php if ($search_provider): ?>
                                                <p class="page-subtitle">No results found for "<?php echo htmlspecialchars($search_provider); ?>"</p>
                                            <?php else: ?>
                                                <p class="page-subtitle">No providers available.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Plans Tab -->
                <div id="plans" class="tab-content">
                    <!-- Search and Filter for Plans -->
                    <div class="search-filter-section no-print">
                        <form method="GET" action="" class="search-filter-form">
                            <input type="hidden" name="tab" value="plans">
                            <div class="search-box">
                                <label class="form-label">Search Plans</label>
                                <input type="text" name="search_plan" class="form-input"
                                    value="<?php echo htmlspecialchars($search_plan); ?>"
                                    placeholder="Search by plan name or provider...">
                            </div>
                            <div class="search-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="benefits-hmo-management.php?tab=plans" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Plans content would go here -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Plan Name</th>
                                    <th>Provider</th>
                                    <th>Annual Limit</th>
                                    <th>Room & Board</th>
                                    <th>Employee Premium</th>
                                    <th>Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($plans)): ?>
                                    <?php foreach ($plans as $plan): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($plan['plan_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($plan['provider_name']); ?></td>
                                            <td class="amount"><?php echo $plan['annual_limit'] ? '₱' . number_format($plan['annual_limit'], 2) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($plan['room_board_type']); ?></td>
                                            <td class="amount"><?php echo $plan['premium_employee'] ? '₱' . number_format($plan['premium_employee'], 2) : 'N/A'; ?></td>
                                            <td>
                                                <span class="status-badge status-active">
                                                    <?php echo htmlspecialchars($plan['status']); ?>
                                                </span>
                                            </td>
                                            <td class="actions-cell no-print">
                                                <button type="button" class="btn btn-info btn-sm" onclick="showPlanDetails(<?php echo $plan['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-file-medical" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No plans found.</p>
                                            <?php if ($search_plan): ?>
                                                <p class="page-subtitle">No results found for "<?php echo htmlspecialchars($search_plan); ?>"</p>
                                            <?php else: ?>
                                                <p class="page-subtitle">No plans available.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Enrollments Tab -->
                <div id="enrollments" class="tab-content">
                    <!-- Search and Filter for Enrollments -->
                    <div class="search-filter-section no-print">
                        <form method="GET" action="" class="search-filter-form">
                            <input type="hidden" name="tab" value="enrollments">
                            <div class="search-box">
                                <label class="form-label">Search Enrollments</label>
                                <input type="text" name="search_enrollment" class="form-input"
                                    value="<?php echo htmlspecialchars($search_enrollment); ?>"
                                    placeholder="Search by employee name, department, or provider...">
                            </div>
                            <div class="search-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="benefits-hmo-management.php?tab=enrollments" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- ADDED: Enrollments Form Container -->
                    <div class="form-container no-print">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-plus-circle"></i>
                                <?php echo isset($_GET['edit_enrollment']) ? 'Edit Enrollment' : 'Enroll Employee'; ?>
                            </h2>
                        </div>
                        <form method="POST" action="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Employee *</label>
                                    <select name="employee_id" class="form-select" required>
                                        <option value="">Select Employee</option>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo $employee['id']; ?>">
                                                <?php echo htmlspecialchars($employee['name']); ?> - <?php echo htmlspecialchars($employee['department']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Provider *</label>
                                    <select name="provider_id" class="form-select" required id="provider_select">
                                        <option value="">Select Provider</option>
                                        <?php foreach ($providers as $provider): ?>
                                            <option value="<?php echo $provider['id']; ?>">
                                                <?php echo htmlspecialchars($provider['provider_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Plan *</label>
                                    <select name="plan_id" class="form-select" required id="plan_select">
                                        <option value="">Select Plan</option>
                                        <?php foreach ($plans as $plan): ?>
                                            <option value="<?php echo $plan['id']; ?>" data-provider="<?php echo $plan['provider_id']; ?>">
                                                <?php echo htmlspecialchars($plan['plan_name']); ?> - <?php echo htmlspecialchars($plan['provider_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Enrollment Date *</label>
                                    <input type="date" name="enrollment_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-textarea" rows="3" placeholder="Any additional notes about this enrollment..."></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="enroll_employee" class="btn btn-success">
                                    <i class="fas fa-user-plus"></i> Enroll Employee
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Enrollments Table -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th>Provider</th>
                                    <th>Plan</th>
                                    <th>Enrollment Date</th>
                                    <th>Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($enrollments)): ?>
                                    <?php foreach ($enrollments as $enrollment): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($enrollment['employee_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($enrollment['department']); ?></td>
                                            <td><?php echo htmlspecialchars($enrollment['provider_name']); ?></td>
                                            <td><?php echo htmlspecialchars($enrollment['plan_name']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($enrollment['enrollment_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-active">
                                                    <?php echo htmlspecialchars($enrollment['status']); ?>
                                                </span>
                                            </td>
                                            <td class="actions-cell no-print">
                                                <button type="button" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <a href="?tab=enrollments&edit_enrollment=<?php echo $enrollment['id']; ?>" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this enrollment?');">
                                                    <input type="hidden" name="id" value="<?php echo $enrollment['id']; ?>">
                                                    <button type="submit" name="delete_enrollment" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-users" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No enrollments found.</p>
                                            <?php if ($search_enrollment): ?>
                                                <p class="page-subtitle">No results found for "<?php echo htmlspecialchars($search_enrollment); ?>"</p>
                                            <?php else: ?>
                                                <p class="page-subtitle">No employee enrollments available.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Dependents Tab -->
                <div id="dependents" class="tab-content">
                    <!-- Search and Filter for Dependents -->
                    <div class="search-filter-section no-print">
                        <form method="GET" action="" class="search-filter-form">
                            <input type="hidden" name="tab" value="dependents">
                            <div class="search-box">
                                <label class="form-label">Search Dependents</label>
                                <input type="text" name="search_dependent" class="form-input"
                                    value="<?php echo htmlspecialchars($search_dependent); ?>"
                                    placeholder="Search by dependent name, employee name, or relationship...">
                            </div>
                            <div class="search-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="benefits-hmo-management.php?tab=dependents" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- ADDED: Dependents Form Container -->
                    <div class="form-container no-print">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-plus-circle"></i>
                                <?php echo isset($_GET['edit_dependent']) ? 'Edit Dependent' : 'Add Dependent'; ?>
                            </h2>
                        </div>
                        <form method="POST" action="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Employee *</label>
                                    <select name="employee_id" class="form-select" required>
                                        <option value="">Select Employee</option>
                                        <?php foreach ($employees as $employee): ?>
                                            <option value="<?php echo $employee['id']; ?>">
                                                <?php echo htmlspecialchars($employee['name']); ?> - <?php echo htmlspecialchars($employee['department']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Dependent Name *</label>
                                    <input type="text" name="name" class="form-input" required placeholder="Full name of dependent">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Relationship *</label>
                                    <select name="relationship" class="form-select" required>
                                        <option value="">Select Relationship</option>
                                        <option value="Spouse">Spouse</option>
                                        <option value="Child">Child</option>
                                        <option value="Parent">Parent</option>
                                        <option value="Sibling">Sibling</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Age</label>
                                    <input type="number" name="age" class="form-input" min="0" max="120" placeholder="Age">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Included in Plan</label>
                                    <select name="included_in_plan" class="form-select">
                                        <option value="1">Yes</option>
                                        <option value="0">No</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Additional Cost</label>
                                    <input type="number" name="additional_cost" class="form-input" step="0.01" min="0" placeholder="Additional monthly cost">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="add_dependent" class="btn btn-success">
                                    <i class="fas fa-user-plus"></i> Add Dependent
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Dependents Table -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Dependent Name</th>
                                    <th>Employee</th>
                                    <th>Relationship</th>
                                    <th>Age</th>
                                    <th>Included in Plan</th>
                                    <th>Additional Cost</th>
                                    <th>Status</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($dependents)): ?>
                                    <?php foreach ($dependents as $dependent): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($dependent['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($dependent['employee_name']); ?></td>
                                            <td><?php echo htmlspecialchars($dependent['relationship']); ?></td>
                                            <td><?php echo htmlspecialchars($dependent['age'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($dependent['included_in_plan']): ?>
                                                    <span class="status-badge status-active">Yes</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-inactive">No</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="amount"><?php echo $dependent['additional_cost'] ? '₱' . number_format($dependent['additional_cost'], 2) : '₱0.00'; ?></td>
                                            <td>
                                                <span class="status-badge status-active">
                                                    <?php echo htmlspecialchars($dependent['status']); ?>
                                                </span>
                                            </td>
                                            <td class="actions-cell no-print">
                                                <button type="button" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <a href="?tab=dependents&edit_dependent=<?php echo $dependent['id']; ?>" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this dependent?');">
                                                    <input type="hidden" name="id" value="<?php echo $dependent['id']; ?>">
                                                    <button type="submit" name="delete_dependent" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-user-friends" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No dependents found.</p>
                                            <?php if ($search_dependent): ?>
                                                <p class="page-subtitle">No results found for "<?php echo htmlspecialchars($search_dependent); ?>"</p>
                                            <?php else: ?>
                                                <p class="page-subtitle">No dependents available.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- UPDATED: Benefits Tab (changed from "DOLE Benefits" to "Benefits") -->
                <div id="benefits" class="tab-content">
                    <!-- Search and Filter for Benefits -->
                    <div class="search-filter-section no-print">
                        <form method="GET" action="" class="search-filter-form">
                            <input type="hidden" name="tab" value="benefits">
                            <div class="search-box">
                                <label class="form-label">Search Benefits</label>
                                <input type="text" name="search_benefit" class="form-input"
                                    value="<?php echo htmlspecialchars($search_benefit); ?>"
                                    placeholder="Search by benefit name or description...">
                            </div>
                            <div class="filter-box">
                                <label class="form-label">Filter by Agency</label>
                                <select name="filter_agency" class="form-select">
                                    <option value="">All Agencies</option>
                                    <option value="SSS" <?php echo $filter_agency === 'SSS' ? 'selected' : ''; ?>>SSS</option>
                                    <option value="PhilHealth" <?php echo $filter_agency === 'PhilHealth' ? 'selected' : ''; ?>>PhilHealth</option>
                                    <option value="Pag-IBIG" <?php echo $filter_agency === 'Pag-IBIG' ? 'selected' : ''; ?>>Pag-IBIG</option>
                                    <option value="BIR" <?php echo $filter_agency === 'BIR' ? 'selected' : ''; ?>>BIR</option>
                                    <option value="DOLE" <?php echo $filter_agency === 'DOLE' ? 'selected' : ''; ?>>DOLE</option>
                                    <option value="Others" <?php echo $filter_agency === 'Others' ? 'selected' : ''; ?>>Others</option>
                                </select>
                            </div>
                            <div class="filter-box">
                                <label class="form-label">Filter by Status</label>
                                <select name="filter_status" class="form-select">
                                    <option value="">All Status</option>
                                    <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $filter_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="Updated" <?php echo $filter_status === 'Updated' ? 'selected' : ''; ?>>Updated</option>
                                </select>
                            </div>
                            <div class="search-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="benefits-hmo-management.php?tab=benefits" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Add Benefit Form -->
                    <div class="form-container no-print">
                        <div class="form-header">
                            <!-- UPDATED: Changed "DOLE Benefit" to "Benefit" -->
                            <h2 class="form-title">
                                <i class="fas fa-plus-circle"></i>
                                <?php echo isset($_GET['edit_benefit']) ? 'Edit Benefit' : 'Add Mandatory Benefit'; ?>
                            </h2>
                        </div>
                        <form method="POST" action="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Benefit Name *</label>
                                    <input type="text" name="benefit_name" class="form-input"
                                        value="<?php
                                                if (isset($_GET['edit_benefit'])) {
                                                    foreach ($benefits as $benefit) {
                                                        if ($benefit['id'] == $_GET['edit_benefit']) {
                                                            echo htmlspecialchars($benefit['benefit_name']);
                                                            break;
                                                        }
                                                    }
                                                }
                                                ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Agency *</label>
                                    <select name="agency" class="form-select" required>
                                        <option value="">Select Agency</option>
                                        <option value="SSS" <?php
                                                            if (isset($_GET['edit_benefit'])) {
                                                                foreach ($benefits as $benefit) {
                                                                    if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['agency'] === 'SSS') {
                                                                        echo 'selected';
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                            ?>>SSS</option>
                                        <option value="PhilHealth" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['agency'] === 'PhilHealth') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>PhilHealth</option>
                                        <option value="Pag-IBIG" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['agency'] === 'Pag-IBIG') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Pag-IBIG</option>
                                        <option value="BIR" <?php
                                                            if (isset($_GET['edit_benefit'])) {
                                                                foreach ($benefits as $benefit) {
                                                                    if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['agency'] === 'BIR') {
                                                                        echo 'selected';
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                            ?>>BIR</option>
                                        <option value="DOLE" <?php
                                                                if (isset($_GET['edit_benefit'])) {
                                                                    foreach ($benefits as $benefit) {
                                                                        if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['agency'] === 'DOLE') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                ?>>DOLE</option>
                                        <option value="Others" <?php
                                                                if (isset($_GET['edit_benefit'])) {
                                                                    foreach ($benefits as $benefit) {
                                                                        if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['agency'] === 'Others') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                ?>>Others</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Benefit Type *</label>
                                    <select name="benefit_type" class="form-select" required>
                                        <option value="">Select Type</option>
                                        <option value="Social Security" <?php
                                                                        if (isset($_GET['edit_benefit'])) {
                                                                            foreach ($benefits as $benefit) {
                                                                                if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Social Security') {
                                                                                    echo 'selected';
                                                                                    break;
                                                                                }
                                                                            }
                                                                        }
                                                                        ?>>Social Security</option>
                                        <option value="Health Insurance" <?php
                                                                            if (isset($_GET['edit_benefit'])) {
                                                                                foreach ($benefits as $benefit) {
                                                                                    if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Health Insurance') {
                                                                                        echo 'selected';
                                                                                        break;
                                                                                    }
                                                                                }
                                                                            }
                                                                            ?>>Health Insurance</option>
                                        <option value="Housing Loan" <?php
                                                                        if (isset($_GET['edit_benefit'])) {
                                                                            foreach ($benefits as $benefit) {
                                                                                if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Housing Loan') {
                                                                                    echo 'selected';
                                                                                    break;
                                                                                }
                                                                            }
                                                                        }
                                                                        ?>>Housing Loan</option>
                                        <option value="Retirement" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Retirement') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Retirement</option>
                                        <option value="Life Insurance" <?php
                                                                        if (isset($_GET['edit_benefit'])) {
                                                                            foreach ($benefits as $benefit) {
                                                                                if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Life Insurance') {
                                                                                    echo 'selected';
                                                                                    break;
                                                                                }
                                                                            }
                                                                        }
                                                                        ?>>Life Insurance</option>
                                        <option value="Disability" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Disability') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Disability</option>
                                        <option value="Maternity" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Maternity') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Maternity</option>
                                        <option value="Sickness" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Sickness') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Sickness</option>
                                        <option value="Death" <?php
                                                                if (isset($_GET['edit_benefit'])) {
                                                                    foreach ($benefits as $benefit) {
                                                                        if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Death') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                ?>>Death</option>
                                        <option value="Emergency Loan" <?php
                                                                        if (isset($_GET['edit_benefit'])) {
                                                                            foreach ($benefits as $benefit) {
                                                                                if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Emergency Loan') {
                                                                                    echo 'selected';
                                                                                    break;
                                                                                }
                                                                            }
                                                                        }
                                                                        ?>>Emergency Loan</option>
                                        <option value="Salary Loan" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Salary Loan') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Salary Loan</option>
                                        <option value="Paternity" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Paternity') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Paternity</option>
                                        <option value="Solo Parent" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Solo Parent') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Solo Parent</option>
                                        <option value="Other" <?php
                                                                if (isset($_GET['edit_benefit'])) {
                                                                    foreach ($benefits as $benefit) {
                                                                        if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['benefit_type'] === 'Other') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                ?>>Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Status *</label>
                                    <select name="status" class="form-select" required>
                                        <option value="Active" <?php
                                                                if (isset($_GET['edit_benefit'])) {
                                                                    foreach ($benefits as $benefit) {
                                                                        if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['status'] === 'Active') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                } else {
                                                                    echo 'selected';
                                                                }
                                                                ?>>Active</option>
                                        <option value="Inactive" <?php
                                                                    if (isset($_GET['edit_benefit'])) {
                                                                        foreach ($benefits as $benefit) {
                                                                            if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['status'] === 'Inactive') {
                                                                                echo 'selected';
                                                                                break;
                                                                            }
                                                                        }
                                                                    }
                                                                    ?>>Inactive</option>
                                        <option value="Updated" <?php
                                                                if (isset($_GET['edit_benefit'])) {
                                                                    foreach ($benefits as $benefit) {
                                                                        if ($benefit['id'] == $_GET['edit_benefit'] && $benefit['status'] === 'Updated') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                ?>>Updated</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Effective Date</label>
                                    <input type="date" name="effective_date" class="form-input"
                                        value="<?php
                                                if (isset($_GET['edit_benefit'])) {
                                                    foreach ($benefits as $benefit) {
                                                        if ($benefit['id'] == $_GET['edit_benefit']) {
                                                            echo htmlspecialchars($benefit['effective_date']);
                                                            break;
                                                        }
                                                    }
                                                } else {
                                                    echo date('Y-m-d');
                                                }
                                                ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Description *</label>
                                <textarea name="description" class="form-textarea" rows="3" required><?php
                                                                                                        if (isset($_GET['edit_benefit'])) {
                                                                                                            foreach ($benefits as $benefit) {
                                                                                                                if ($benefit['id'] == $_GET['edit_benefit']) {
                                                                                                                    echo htmlspecialchars($benefit['description']);
                                                                                                                    break;
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                        ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Coverage</label>
                                <textarea name="coverage" class="form-textarea" rows="2"><?php
                                                                                            if (isset($_GET['edit_benefit'])) {
                                                                                                foreach ($benefits as $benefit) {
                                                                                                    if ($benefit['id'] == $_GET['edit_benefit']) {
                                                                                                        echo htmlspecialchars($benefit['coverage'] ?? '');
                                                                                                        break;
                                                                                                    }
                                                                                                }
                                                                                            }
                                                                                            ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Eligibility</label>
                                <textarea name="eligibility" class="form-textarea" rows="2"><?php
                                                                                            if (isset($_GET['edit_benefit'])) {
                                                                                                foreach ($benefits as $benefit) {
                                                                                                    if ($benefit['id'] == $_GET['edit_benefit']) {
                                                                                                        echo htmlspecialchars($benefit['eligibility'] ?? '');
                                                                                                        break;
                                                                                                    }
                                                                                                }
                                                                                            }
                                                                                            ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Required Documents</label>
                                <textarea name="required_documents" class="form-textarea" rows="2"><?php
                                                                                                    if (isset($_GET['edit_benefit'])) {
                                                                                                        foreach ($benefits as $benefit) {
                                                                                                            if ($benefit['id'] == $_GET['edit_benefit']) {
                                                                                                                echo htmlspecialchars($benefit['required_documents'] ?? '');
                                                                                                                break;
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                    ?></textarea>
                            </div>
                            <div class="form-actions">
                                <?php if (isset($_GET['edit_benefit'])): ?>
                                    <input type="hidden" name="id" value="<?php echo $_GET['edit_benefit']; ?>">
                                    <button type="submit" name="update_benefit" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Benefit
                                    </button>
                                    <a href="benefits-hmo-management.php?tab=benefits" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="add_benefit" class="btn btn-success">
                                        <i class="fas fa-plus"></i> Add Benefit
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Benefits Statistics -->
                    <div class="form-container">
                        <h2 class="form-title">
                            <i class="fas fa-chart-pie"></i>
                            Benefits Summary by Agency
                        </h2>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Agency</th>
                                    <th>Number of Benefits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($benefit_stats)): ?>
                                    <?php foreach ($benefit_stats as $stat): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($stat['agency']); ?></strong></td>
                                            <td><?php echo $stat['count']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="2" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-chart-pie" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No benefit statistics available.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Benefits Table - UPDATED: Removed contribution columns -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Benefit Name</th>
                                    <th>Agency</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Effective Date</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($benefits)): ?>
                                    <?php foreach ($benefits as $benefit): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($benefit['benefit_name']); ?></strong>
                                                <div style="font-size: 0.8rem; color: #6c757d; margin-top: 0.25rem;">
                                                    <?php echo substr($benefit['description'], 0, 100); ?>...
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($benefit['agency']); ?></td>
                                            <td><?php echo htmlspecialchars($benefit['benefit_type']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($benefit['status']); ?>">
                                                    <?php echo htmlspecialchars($benefit['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($benefit['effective_date'])); ?></td>
                                            <td class="actions-cell no-print">
                                                <button type="button" class="btn btn-info btn-sm" onclick="showBenefitDetails(<?php echo $benefit['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <a href="?tab=benefits&edit_benefit=<?php echo $benefit['id']; ?>" class="btn btn-warning btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this benefit?');">
                                                    <input type="hidden" name="id" value="<?php echo $benefit['id']; ?>">
                                                    <button type="submit" name="delete_benefit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-file-contract" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No mandatory benefits found.</p>
                                            <?php if ($search_benefit || $filter_agency || $filter_status): ?>
                                                <p class="page-subtitle">No results found for your search criteria.</p>
                                            <?php else: ?>
                                                <p class="page-subtitle">Add your first mandatory benefit using the form above.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Reimbursement Tab -->
                <div id="reimbursement" class="tab-content">
                    <!-- Search and Filter for Reimbursements -->
                    <div class="search-filter-section no-print">
                        <form method="GET" action="" class="search-filter-form">
                            <input type="hidden" name="tab" value="reimbursement">
                            <div class="search-box">
                                <label class="form-label">Search Reimbursements</label>
                                <input type="text" name="search_reimbursement" class="form-input"
                                    value="<?php echo htmlspecialchars($search_reimbursement ?? ''); ?>"
                                    placeholder="Search by employee ID, name, or type...">
                            </div>
                            <div class="filter-box">
                                <label class="form-label">Status</label>
                                <select name="filter_status" class="form-input">
                                    <option value="">All Status</option>
                                    <option value="Pending" <?php echo ($filter_status ?? '') === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Approved" <?php echo ($filter_status ?? '') === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="Rejected" <?php echo ($filter_status ?? '') === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    <option value="Send Back" <?php echo ($filter_status ?? '') === 'Send Back' ? 'selected' : ''; ?>>Send Back</option>
                                </select>
                            </div>
                            <div class="search-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="?tab=reimbursement" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Reimbursement Requests Table -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Expense Date</th>
                                    <th>Status</th>
                                    <th>Submission Date</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $search_reimbursement = $_GET['search_reimbursement'] ?? '';
                                $filter_status = $_GET['filter_status'] ?? '';
                                $reimbursements = getReimbursements($pdo, $search_reimbursement, $filter_status);

                                if (!empty($reimbursements)):
                                    foreach ($reimbursements as $reimbursement):
                                ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($reimbursement['employee_id'] . ' - ' . $reimbursement['employee_name']); ?></td>
                                            <td><?php echo htmlspecialchars($reimbursement['reimbursement_type']); ?></td>
                                            <td>₱<?php echo number_format($reimbursement['amount_requested'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($reimbursement['expense_date']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo strtolower($reimbursement['status']); ?>">
                                                    <?php echo htmlspecialchars($reimbursement['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($reimbursement['submission_date']); ?></td>
                                            <td class="no-print">
                                                <?php if ($reimbursement['status'] === 'Pending'): ?>
                                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to approve this reimbursement?');">
                                                        <input type="hidden" name="id" value="<?php echo $reimbursement['id']; ?>">
                                                        <button type="submit" name="approve_reimbursement" class="btn btn-success btn-sm">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    </form>
                                                    <button class="btn btn-danger btn-sm" onclick="rejectReimbursement(<?php echo $reimbursement['id']; ?>)">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                    <button class="btn btn-warning btn-sm" onclick="sendBackReimbursement(<?php echo $reimbursement['id']; ?>)">
                                                        <i class="fas fa-undo"></i> Send Back
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn btn-info btn-sm" onclick="viewReimbursementDetails(<?php echo $reimbursement['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php
                                    endforeach;
                                else:
                                    ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-money-bill-wave" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No reimbursement requests found.</p>
                                            <?php if ($search_reimbursement || $filter_status): ?>
                                                <p class="page-subtitle">No results found for your search criteria.</p>
                                            <?php else: ?>
                                                <p class="page-subtitle">No reimbursement requests have been submitted yet.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Documents Tab -->
                <div id="documents" class="tab-content">
                    <!-- Search and Filter for Documents -->
                    <div class="search-filter-section no-print">
                        <form method="GET" action="" class="search-filter-form">
                            <input type="hidden" name="tab" value="documents">
                            <div class="search-box">
                                <label class="form-label">Search Documents</label>
                                <input type="text" name="search_document" class="form-input"
                                    value="<?php echo htmlspecialchars($search_document); ?>"
                                    placeholder="Search by document name, type, or provider...">
                            </div>
                            <div class="search-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <a href="benefits-hmo-management.php?tab=documents" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>

                    <!-- Documents content would go here -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Document Name</th>
                                    <th>Type</th>
                                    <th>Provider</th>
                                    <th>Description</th>
                                    <th class="no-print">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($documents)): ?>
                                    <?php foreach ($documents as $document): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($document['document_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($document['document_type']); ?></td>
                                            <td><?php echo htmlspecialchars($document['provider_name']); ?></td>
                                            <td><?php echo htmlspecialchars(substr($document['description'] ?? '', 0, 100)) . '...'; ?></td>
                                            <td class="actions-cell no-print">
                                                <button type="button" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-file-alt" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No documents found.</p>
                                            <?php if ($search_document): ?>
                                                <p class="page-subtitle">No results found for "<?php echo htmlspecialchars($search_document); ?>"</p>
                                            <?php else: ?>
                                                <p class="page-subtitle">No documents available.</p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Reports Tab -->
                <div id="reports" class="tab-content">
                    <div class="form-container no-print">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-chart-bar"></i>
                                Benefits & HMO Reports
                            </h2>
                            <button onclick="window.print()" class="btn print-btn">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                        <p>Generate printable reports of benefits enrollment and provider utilization.</p>
                    </div>

                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo $stats['total_enrollments']; ?></div>
                            <div class="stat-label">Total Employees with HMO</div>
                            <i class="fas fa-users" style="float: right; color: #4e73df; font-size: 1.5rem;"></i>
                        </div>
                        <div class="stat-card success">
                            <div class="stat-value"><?php echo $stats['total_dependents']; ?></div>
                            <div class="stat-label">Total Dependents</div>
                            <i class="fas fa-user-friends" style="float: right; color: #1cc88a; font-size: 1.5rem;"></i>
                        </div>
                        <div class="stat-card info">
                            <div class="stat-value"><?php echo $stats['total_providers']; ?></div>
                            <div class="stat-label">Active Providers</div>
                            <i class="fas fa-building" style="float: right; color: #36b9cc; font-size: 1.5rem;"></i>
                        </div>
                        <div class="stat-card purple">
                            <div class="stat-value"><?php echo $stats['total_plans']; ?></div>
                            <div class="stat-label">Available Plans</div>
                            <i class="fas fa-file-medical" style="float: right; color: #6f42c1; font-size: 1.5rem;"></i>
                        </div>
                        <div class="stat-card orange">
                            <div class="stat-value"><?php echo $stats['total_benefits']; ?></div>
                            <!-- UPDATED: Changed from "DOLE Benefits" to "Benefits" -->
                            <div class="stat-label">Benefits</div>
                            <i class="fas fa-file-contract" style="float: right; color: #fd7e14; font-size: 1.5rem;"></i>
                        </div>
                    </div>

                    <div class="form-container">
                        <h2 class="form-title">
                            <i class="fas fa-chart-pie"></i>
                            Enrollment by Provider
                        </h2>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Provider</th>
                                    <th>Employee Count</th>
                                    <th>Dependent Count</th>
                                    <th>Total Coverage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($providers)): ?>
                                    <?php foreach ($providers as $provider): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($provider['provider_name']); ?></strong></td>
                                            <td><?php echo $provider_counts[$provider['provider_name']] ?? 0; ?></td>
                                            <td><?php echo $dependent_counts[$provider['provider_name']] ?? 0; ?></td>
                                            <td><strong><?php echo ($provider_counts[$provider['provider_name']] ?? 0) + ($dependent_counts[$provider['provider_name']] ?? 0); ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-chart-bar" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No provider data available.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-container">
                        <h2 class="form-title">
                            <i class="fas fa-list"></i>
                            Mandatory Benefits Report
                        </h2>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Benefit Name</th>
                                    <th>Agency</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($benefits)): ?>
                                    <?php foreach ($benefits as $benefit): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($benefit['benefit_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($benefit['agency']); ?></td>
                                            <td><?php echo htmlspecialchars($benefit['benefit_type']); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($benefit['status']); ?>">
                                                    <?php echo htmlspecialchars($benefit['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-file-contract" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No benefit data available.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-container">
                        <h2 class="form-title">
                            <i class="fas fa-list"></i>
                            Employee Enrollment List
                        </h2>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th>Provider</th>
                                    <th>Plan</th>
                                    <th>Enrollment Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($enrollments)): ?>
                                    <?php foreach ($enrollments as $enrollment): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($enrollment['employee_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($enrollment['department']); ?></td>
                                            <td><?php echo htmlspecialchars($enrollment['provider_name']); ?></td>
                                            <td><?php echo htmlspecialchars($enrollment['plan_name']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($enrollment['enrollment_date'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-users" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No enrollment data available.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-container">
                        <h2 class="form-title">
                            <i class="fas fa-user-friends"></i>
                            Dependent List
                        </h2>
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
                                <?php if (!empty($dependents)): ?>
                                    <?php foreach ($dependents as $dependent): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($dependent['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($dependent['employee_name']); ?></td>
                                            <td><?php echo htmlspecialchars($dependent['relationship']); ?></td>
                                            <td><?php echo htmlspecialchars($dependent['age'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($dependent['included_in_plan']): ?>
                                                    <span class="status-badge status-active">Yes</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-inactive">No</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 2rem;">
                                            <i class="fas fa-user-friends" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                            <p>No dependent data available.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Plan Details Modal -->
    <div id="planDetailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: var(--border-radius); max-width: 800px; width: 90%; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 1.5rem;">
                <h3 style="margin: 0;" id="modalPlanName">Plan Details</h3>
                <button onclick="closePlanDetails()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div id="planDetailsContent">
                <!-- Plan details will be loaded here -->
            </div>
            <div style="margin-top: 1.5rem; text-align: right;">
                <button onclick="closePlanDetails()" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <!-- Benefit Details Modal -->
    <div id="benefitDetailsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 2rem; border-radius: var(--border-radius); max-width: 800px; width: 90%; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 1.5rem;">
                <h3 style="margin: 0;" id="modalBenefitName">Benefit Details</h3>
                <button onclick="closeBenefitDetails()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
            </div>
            <div id="benefitDetailsContent">
                <!-- Benefit details will be loaded here -->
            </div>
            <div style="margin-top: 1.5rem; text-align: right;">
                <button onclick="closeBenefitDetails()" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Check for tab parameter in URL
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab') || 'providers';

            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');

            // Activate the correct tab
            tabs.forEach(tab => {
                if (tab.getAttribute('data-tab') === activeTab) {
                    tab.classList.add('active');
                    document.getElementById(activeTab).classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });

            tabContents.forEach(content => {
                if (content.id === activeTab) {
                    content.classList.add('active');
                } else {
                    content.classList.remove('active');
                }
            });

            // Tab click handler
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');

                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));

                    // Add active class to current tab and content
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');

                    // Update URL without page reload
                    const url = new URL(window.location);
                    url.searchParams.set('tab', tabId);
                    window.history.pushState({}, '', url);
                });
            });

            // Plan filtering based on provider selection
            const providerSelect = document.getElementById('provider_select');
            const planSelect = document.getElementById('plan_select');

            if (providerSelect && planSelect) {
                providerSelect.addEventListener('change', function() {
                    const providerId = this.value;
                    const planOptions = planSelect.querySelectorAll('option');

                    // Show all options if no provider is selected
                    if (!providerId) {
                        planOptions.forEach(option => {
                            option.style.display = '';
                        });
                        return;
                    }

                    // Show only plans for the selected provider
                    planOptions.forEach(option => {
                        if (option.value === '' || option.getAttribute('data-provider') === providerId) {
                            option.style.display = '';
                        } else {
                            option.style.display = 'none';
                        }
                    });

                    // Reset plan selection if the current selection doesn't match the provider
                    const selectedPlanProvider = planSelect.options[planSelect.selectedIndex]?.getAttribute('data-provider');
                    if (selectedPlanProvider && selectedPlanProvider !== providerId) {
                        planSelect.value = '';
                    }
                });

                // Trigger change event on page load if a provider is already selected
                if (providerSelect.value) {
                    providerSelect.dispatchEvent(new Event('change'));
                }
            }
        });

        // Plan details modal functions
        function showPlanDetails(planId) {
            const plans = <?php echo json_encode($plans); ?>;
            const plan = plans.find(p => p.id == planId);

            if (plan) {
                document.getElementById('modalPlanName').textContent = plan.plan_name + ' - ' + plan.provider_name;

                let content = `
                    <div style="margin-bottom: 1rem;">
                        <strong>Provider:</strong> ${plan.provider_name}
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Annual Limit:</strong> ${plan.annual_limit ? '₱' + parseFloat(plan.annual_limit).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : 'N/A'}
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Room & Board:</strong> ${plan.room_board_type || 'N/A'}
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Employee Premium:</strong> ${plan.premium_employee ? '₱' + parseFloat(plan.premium_employee).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : 'N/A'}
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Dependent Premium:</strong> ${plan.premium_dependent ? '₱' + parseFloat(plan.premium_dependent).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : 'N/A'}
                    </div>
                `;

                if (plan.coverage_outpatient) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Outpatient Coverage:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${plan.coverage_outpatient}</div>
                        </div>
                    `;
                }

                if (plan.coverage_inpatient) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Inpatient Coverage:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${plan.coverage_inpatient}</div>
                        </div>
                    `;
                }

                if (plan.coverage_emergency) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Emergency Coverage:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${plan.coverage_emergency}</div>
                        </div>
                    `;
                }

                if (plan.coverage_dental) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Dental Coverage:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${plan.coverage_dental}</div>
                        </div>
                    `;
                }

                if (plan.coverage_vision) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Vision Coverage:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${plan.coverage_vision}</div>
                        </div>
                    `;
                }

                if (plan.addon_benefits) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Add-on Benefits:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${plan.addon_benefits}</div>
                        </div>
                    `;
                }

                if (plan.exclusions) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Exclusions:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${plan.exclusions}</div>
                        </div>
                    `;
                }

                document.getElementById('planDetailsContent').innerHTML = content;
                document.getElementById('planDetailsModal').style.display = 'flex';
            }
        }

        function closePlanDetails() {
            document.getElementById('planDetailsModal').style.display = 'none';
        }

        // Benefit details modal functions - UPDATED: Removed contribution display
        function showBenefitDetails(benefitId) {
            const benefits = <?php echo json_encode($benefits); ?>;
            const benefit = benefits.find(b => b.id == benefitId);

            if (benefit) {
                document.getElementById('modalBenefitName').textContent = benefit.benefit_name;

                let content = `
                    <div style="margin-bottom: 1rem;">
                        <strong>Agency:</strong> ${benefit.agency}
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Benefit Type:</strong> ${benefit.benefit_type}
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Status:</strong> <span class="status-badge status-${benefit.status.toLowerCase()}">${benefit.status}</span>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Effective Date:</strong> ${new Date(benefit.effective_date).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <strong>Description:</strong>
                        <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${benefit.description}</div>
                    </div>
                `;

                if (benefit.coverage) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Coverage:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${benefit.coverage}</div>
                        </div>
                    `;
                }

                if (benefit.eligibility) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Eligibility:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${benefit.eligibility}</div>
                        </div>
                    `;
                }

                if (benefit.required_documents) {
                    content += `
                        <div style="margin-bottom: 1rem;">
                            <strong>Required Documents:</strong>
                            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${benefit.required_documents}</div>
                        </div>
                    `;
                }

                content += `
                    <div style="margin-top: 1rem; padding: 0.75rem; background: #fff3cd; border-radius: 0.25rem; border: 1px solid #ffeaa7;">
                        <strong><i class="fas fa-info-circle"></i> Note:</strong> This is a mandatory benefit required by Philippine law under ${benefit.agency} regulations.
                    </div>
                `;

                document.getElementById('benefitDetailsContent').innerHTML = content;
                document.getElementById('benefitDetailsModal').style.display = 'flex';
            }
        }

        function closeBenefitDetails() {
            document.getElementById('benefitDetailsModal').style.display = 'none';
        }

        // Reimbursement functions
        function approveReimbursement(id) {
            if (confirm('Are you sure you want to approve this reimbursement request?')) {
                // Here you would typically make an AJAX call to update the status
                alert('Reimbursement request #' + id + ' has been approved.');
                // Refresh the page or update the table
                location.reload();
            }
        }

        function rejectReimbursement(id) {
            const reason = prompt('Please provide a reason for rejection:');
            if (reason !== null && reason.trim() !== '') {
                // Create a form to submit the rejection
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = id;

                const reasonInput = document.createElement('input');
                reasonInput.type = 'hidden';
                reasonInput.name = 'rejection_reason';
                reasonInput.value = reason;

                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'reject_reimbursement';
                submitInput.value = '1';

                form.appendChild(idInput);
                form.appendChild(reasonInput);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function sendBackReimbursement(id) {
            const reason = prompt('Please provide a reason for sending back (e.g., missing documents, incorrect information):');
            if (reason !== null && reason.trim() !== '') {
                // Create a form to submit the send back request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'id';
                idInput.value = id;

                const reasonInput = document.createElement('input');
                reasonInput.type = 'hidden';
                reasonInput.name = 'send_back_reason';
                reasonInput.value = reason;

                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'send_back_reimbursement';
                submitInput.value = '1';

                form.appendChild(idInput);
                form.appendChild(reasonInput);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewReimbursementDetails(id) {
            // Get reimbursement data from PHP-generated JSON
            const reimbursements = <?php echo json_encode($reimbursements); ?>;
            const reimbursement = reimbursements.find(r => r.id == id);

            if (!reimbursement) {
                alert('Reimbursement not found.');
                return;
            }

            let content = `
                <div style="margin-bottom: 1rem;">
                    <strong>Employee:</strong> ${reimbursement.employee_name} (${reimbursement.employee_id})
                </div>
                <div style="margin-bottom: 1rem;">
                    <strong>Type:</strong> ${reimbursement.reimbursement_type}
                </div>
                <div style="margin-bottom: 1rem;">
                    <strong>Amount Requested:</strong> ₱${parseFloat(reimbursement.amount_requested).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                </div>
                ${reimbursement.amount_approved ? `
                <div style="margin-bottom: 1rem;">
                    <strong>Amount Approved:</strong> ₱${parseFloat(reimbursement.amount_approved).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                </div>
                ` : ''}
                <div style="margin-bottom: 1rem;">
                    <strong>Expense Date:</strong> ${reimbursement.expense_date}
                </div>
                <div style="margin-bottom: 1rem;">
                    <strong>Submission Date:</strong> ${reimbursement.submission_date}
                </div>
                <div style="margin-bottom: 1rem;">
                    <strong>Status:</strong> <span class="status-badge ${reimbursement.status.toLowerCase()}">${reimbursement.status}</span>
                </div>
                ${reimbursement.approved_by ? `
                <div style="margin-bottom: 1rem;">
                    <strong>Processed By:</strong> ${reimbursement.approved_by}
                </div>
                <div style="margin-bottom: 1rem;">
                    <strong>Processed Date:</strong> ${reimbursement.processed_date || 'N/A'}
                </div>
                ` : ''}
                <div style="margin-bottom: 1rem;">
                    <strong>Description:</strong>
                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8f9fa; border-radius: 0.25rem;">${reimbursement.description}</div>
                </div>
                ${reimbursement.rejection_reason ? `
                <div style="margin-bottom: 1rem;">
                    <strong>Rejection Reason:</strong>
                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #f8d7da; border-radius: 0.25rem; color: #721c24;">${reimbursement.rejection_reason}</div>
                </div>
                ` : ''}
                ${reimbursement.send_back_reason ? `
                <div style="margin-bottom: 1rem;">
                    <strong>Send Back Reason:</strong>
                    <div style="margin-top: 0.5rem; padding: 0.5rem; background: #fff3cd; border-radius: 0.25rem; color: #856404;">${reimbursement.send_back_reason}</div>
                </div>
                ` : ''}
            `;

            if (reimbursement.receipt_file) {
                content += `
                    <div style="margin-bottom: 1rem;">
                        <strong>Receipt/Invoice:</strong>
                        <a href="../uploads/reimbursements/${reimbursement.receipt_file}" target="_blank" class="btn btn-info btn-sm" style="margin-left: 0.5rem;">
                            <i class="fas fa-file"></i> View Receipt
                        </a>
                    </div>
                `;
            }

            // Create or update modal for reimbursement details
            let modal = document.getElementById('reimbursementDetailsModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'reimbursementDetailsModal';
                modal.className = 'modal';
                modal.innerHTML = `
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title">
                                <i class="fas fa-file-invoice-dollar"></i>
                                Reimbursement Details
                            </h3>
                            <button class="close" onclick="closeReimbursementDetails()">&times;</button>
                        </div>
                        <div class="modal-body" id="reimbursementDetailsContent">
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" onclick="closeReimbursementDetails()">Close</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
            }

            document.getElementById('reimbursementDetailsContent').innerHTML = content;
            modal.style.display = 'flex';
        }

        function closeReimbursementDetails() {
            const modal = document.getElementById('reimbursementDetailsModal');
            if (modal) {
                modal.style.display = 'none';
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('reimbursementDetailsModal');
            if (modal && event.target === modal) {
                closeReimbursementDetails();
            }
        });

        // Close modal with ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeReimbursementDetails();
            }
        });
    </script>
</body>

</html>
<?php ob_end_flush(); ?>