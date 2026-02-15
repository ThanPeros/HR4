<?php
ob_start(); // Start output buffering immediately
// Add proper error handling at the very beginning
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include external database configuration
require_once "../config/db.php";

// Authentication Check
if (!isset($_SESSION['user']) || !isset($_SESSION['role'])) {
    header('Location: ../index.php');
    exit;
}

// File upload configuration
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2MB
$allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
$allowed_doc_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

// Database connection function (PDO version)
function getDB()
{
    global $pdo;
    if (!$pdo) {
        die("Database connection failed");
    }
    return $pdo;
}

// Initialize database tables
function initDatabase()
{
    $pdo = getDB();

    try {
        // Add columns to employees table if they don't exist
        $alter_queries = [
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) NULL AFTER `name`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(20) NULL AFTER `email`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `photo` VARCHAR(255) NULL AFTER `address`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `gender` VARCHAR(20) NULL AFTER `photo`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `age` INT(3) NULL AFTER `gender`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `employee_id` VARCHAR(20) NULL AFTER `id`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `birth_date` DATE NULL AFTER `age`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `birth_place` VARCHAR(100) NULL AFTER `birth_date`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `civil_status` VARCHAR(20) NULL AFTER `birth_place`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `nationality` VARCHAR(50) NULL AFTER `civil_status`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `religion` VARCHAR(50) NULL AFTER `nationality`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `sss_no` VARCHAR(20) NULL AFTER `religion`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `tin_no` VARCHAR(20) NULL AFTER `sss_no`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `philhealth_no` VARCHAR(20) NULL AFTER `tin_no`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `pagibig_no` VARCHAR(20) NULL AFTER `philhealth_no`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `passport_no` VARCHAR(20) NULL AFTER `pagibig_no`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `contract` VARCHAR(50) NULL AFTER `job_title`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `date_hired` DATE NULL AFTER `contract`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `date_regularized` DATE NULL AFTER `date_hired`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `resume_file` VARCHAR(255) NULL AFTER `photo`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `diploma_file` VARCHAR(255) NULL AFTER `resume_file`",
            
            // New Columns for Extended Profile
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `manager` VARCHAR(100) NULL AFTER `department`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `work_schedule` VARCHAR(100) NULL AFTER `manager`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `pay_grade` VARCHAR(50) NULL AFTER `salary`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `bank_name` VARCHAR(50) NULL AFTER `pay_grade`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `bank_account_no` VARCHAR(50) NULL AFTER `bank_name`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `hmo_provider` VARCHAR(50) NULL AFTER `bank_account_no`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `hmo_number` VARCHAR(50) NULL AFTER `hmo_provider`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `leave_credits_vacation` FLOAT DEFAULT 0 AFTER `hmo_number`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `leave_credits_sick` FLOAT DEFAULT 0 AFTER `leave_credits_vacation`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `job_description` TEXT NULL AFTER `job_title`"
        ];

        foreach ($alter_queries as $query) {
            try {
                $pdo->exec($query);
            } catch (Exception $e) {
                // Column might already exist, continue
                error_log("Alter table warning: " . $e->getMessage());
            }
        }

        // Create emergency_contacts table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `emergency_contacts` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `contact_name` varchar(100) NOT NULL,
            `relationship` varchar(50) NOT NULL,
            `phone` varchar(20) NOT NULL,
            `email` varchar(100) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `is_primary` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create family_dependents table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `family_dependents` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `name` varchar(100) NOT NULL,
            `relationship` varchar(50) NOT NULL,
            `birth_date` date DEFAULT NULL,
            `contact_number` varchar(20) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create salary_history table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `salary_history` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `effective_date` date NOT NULL,
            `type` varchar(50) NOT NULL DEFAULT 'Increase',
            `remarks` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create disciplinary_cases table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `disciplinary_cases` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `violation` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `action_taken` varchar(100) DEFAULT NULL,
            `date_reported` date NOT NULL,
            `date_closed` date DEFAULT NULL,
            `status` varchar(20) DEFAULT 'Open',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Create performance_reviews table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `performance_reviews` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `review_date` date NOT NULL,
            `rating` varchar(20) NOT NULL,
            `evaluator` varchar(100) DEFAULT NULL,
            `comments` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create educational_background table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `educational_background` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `level` varchar(50) NOT NULL,
            `school_name` varchar(100) NOT NULL,
            `course` varchar(100) DEFAULT NULL,
            `year_graduated` year DEFAULT NULL,
            `honors` varchar(100) DEFAULT NULL,
            `diploma_file` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create seminars table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `seminars` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `seminar_name` varchar(100) NOT NULL,
            `organizer` varchar(100) NOT NULL,
            `date_from` date NOT NULL,
            `date_to` date NOT NULL,
            `hours` int(4) DEFAULT NULL,
            `certificate` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create skills table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `skills` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `skill_name` varchar(100) NOT NULL,
            `proficiency_level` varchar(20) DEFAULT NULL,
            `certification` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Create work_experience table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `work_experience` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `company_name` varchar(100) NOT NULL,
            `position` varchar(100) NOT NULL,
            `date_from` date NOT NULL,
            `date_to` date DEFAULT NULL,
            `is_current` tinyint(1) DEFAULT 0,
            `years_experience` int(2) DEFAULT NULL,
            `reference_name` varchar(100) DEFAULT NULL,
            `reference_contact` varchar(50) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Create employee_documents table (201 Files)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_documents` (
            `id` int(6) UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` int(6) UNSIGNED NOT NULL,
            `document_name` varchar(100) NOT NULL,
            `document_type` varchar(50) NOT NULL,
            `file_path` varchar(255) NOT NULL,
            `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `employee_id` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Update existing employees with sample data if needed
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `employees` WHERE `employee_id` IS NULL LIMIT 1");
        $row = $stmt->fetch();
        if ($row['count'] > 0) {
            $pdo->exec("UPDATE `employees` SET 
                `employee_id` = CONCAT('EMP', LPAD(`id`, 4, '0')),
                `email` = CONCAT(LOWER(REPLACE(`name`, ' ', '.')), '@company.com'),
                `phone` = CONCAT('555-', LPAD(FLOOR(RAND() * 1000), 3, '0'), '-', LPAD(FLOOR(RAND() * 10000), 4, '0'))
            WHERE `employee_id` IS NULL");
        }
    } catch (Exception $e) {
        error_log("Database initialization error: " . $e->getMessage());
    }
}

// Handle file upload
function handleFileUpload($file, $employee_id, $type = 'photo')
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File too large'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_types = $GLOBALS['allowed_types'];
    if ($type === 'resume' || $type === 'diploma' || $type === 'certificate' || $type === 'education_diploma' || $type === 'certification' || $type === 'document') {
        $allowed_types = array_merge($allowed_types, $GLOBALS['allowed_doc_types']);
    }

    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = $type . '_' . $employee_id . '_' . time() . '.' . $extension;
    $filepath = UPLOAD_DIR . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => true, 'filename' => $filename];
    }

    return ['success' => false, 'message' => 'Failed to save file'];
}

// Function to Get Employees 
function getEmployees($department = null, $status = null, $search = null) {
    global $pdo;
    $sql = "SELECT * FROM employees WHERE 1=1";
    $params = [];

    if ($department) {
        $sql .= " AND department = ?";
        $params[] = $department;
    }
    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }
    if ($search) {
        $sql .= " AND (name LIKE ? OR employee_id LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get family dependents
function getDependents($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `family_dependents` WHERE employee_id = ? ORDER BY birth_date DESC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get salary history
function getSalaryHistory($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `salary_history` WHERE employee_id = ? ORDER BY effective_date DESC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get disciplinary cases
function getDisciplinaryCases($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `disciplinary_cases` WHERE employee_id = ? ORDER BY date_reported DESC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get performance reviews
function getPerformanceReviews($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `performance_reviews` WHERE employee_id = ? ORDER BY review_date DESC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get employee by ID
function getEmployee($id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `employees` WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Get emergency contacts for employee
function getEmergencyContacts($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `emergency_contacts` WHERE employee_id = ? ORDER BY is_primary DESC, contact_name ASC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get educational background for employee
function getEducationalBackground($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `educational_background` WHERE employee_id = ? ORDER BY 
        CASE level 
            WHEN 'Doctorate' THEN 1
            WHEN 'Master' THEN 2
            WHEN 'Bachelor' THEN 3
            WHEN 'College' THEN 4
            WHEN 'High School' THEN 5
            WHEN 'Elementary' THEN 6
            ELSE 7
        END");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get seminars for employee
function getSeminars($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `seminars` WHERE employee_id = ? ORDER BY date_from DESC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get skills for employee
function getSkills($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `skills` WHERE employee_id = ? ORDER BY proficiency_level DESC, skill_name ASC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Get work experience for employee
function getWorkExperience($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `work_experience` WHERE employee_id = ? ORDER BY date_from DESC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Update employee profile
function updateEmployee($id, $data)
{
    $pdo = getDB();
    $fields = [];
    $params = [];

    $allowed_fields = [
        'name',
        'email',
        'phone',
        'address',
        'department',
        'job_title',
        'contract',
        'gender',
        'age',
        'status',
        'salary',
        'birth_date',
        'birth_place',
        'civil_status',
        'nationality',
        'religion',
        'sss_no',
        'tin_no',
        'philhealth_no',
        'pagibig_no',
        'passport_no',
        'date_hired',
        'date_regularized',
        'manager',
        'work_schedule',
        'pay_grade',
        'bank_name',
        'bank_account_no',
        'hmo_provider',
        'hmo_number',
        'leave_credits_vacation',
        'leave_credits_sick',
        'job_description'
    ];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $fields[] = "`$field` = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($fields)) {
        return false;
    }

    $params[] = $id;

    $sql = "UPDATE `employees` SET " . implode(', ', $fields) . " WHERE id = ?";

    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

// Add/update emergency contact
function saveEmergencyContact($employee_id, $contact_data, $contact_id = null)
{
    $pdo = getDB();

    if ($contact_id) {
        // Update existing contact
        $sql = "UPDATE `emergency_contacts` SET contact_name=?, relationship=?, phone=?, email=?, address=?, is_primary=? WHERE id=? AND employee_id=?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $contact_data['contact_name'],
            $contact_data['relationship'],
            $contact_data['phone'],
            $contact_data['email'],
            $contact_data['address'],
            $contact_data['is_primary'],
            $contact_id,
            $employee_id
        ]);
    } else {
        // Insert new contact
        $sql = "INSERT INTO `emergency_contacts` (employee_id, contact_name, relationship, phone, email, address, is_primary) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $employee_id,
            $contact_data['contact_name'],
            $contact_data['relationship'],
            $contact_data['phone'],
            $contact_data['email'],
            $contact_data['address'],
            $contact_data['is_primary']
        ]);
    }
}

// Add/update educational background
function saveEducationalBackground($employee_id, $education_data, $education_id = null)
{
    $pdo = getDB();

    if ($education_id) {
        // Update existing education
        $sql = "UPDATE `educational_background` SET level=?, school_name=?, course=?, year_graduated=?, honors=?, diploma_file=? WHERE id=? AND employee_id=?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $education_data['level'],
            $education_data['school_name'],
            $education_data['course'],
            $education_data['year_graduated'],
            $education_data['honors'],
            $education_data['diploma_file'],
            $education_id,
            $employee_id
        ]);
    } else {
        // Insert new education
        $sql = "INSERT INTO `educational_background` (employee_id, level, school_name, course, year_graduated, honors, diploma_file) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $employee_id,
            $education_data['level'],
            $education_data['school_name'],
            $education_data['course'],
            $education_data['year_graduated'],
            $education_data['honors'],
            $education_data['diploma_file']
        ]);
    }
}

// Add/update seminar
function saveSeminar($employee_id, $seminar_data, $seminar_id = null)
{
    $pdo = getDB();

    if ($seminar_id) {
        // Update existing seminar
        $sql = "UPDATE `seminars` SET seminar_name=?, organizer=?, date_from=?, date_to=?, hours=?, certificate=? WHERE id=? AND employee_id=?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $seminar_data['seminar_name'],
            $seminar_data['organizer'],
            $seminar_data['date_from'],
            $seminar_data['date_to'],
            $seminar_data['hours'],
            $seminar_data['certificate'],
            $seminar_id,
            $employee_id
        ]);
    } else {
        // Insert new seminar
        $sql = "INSERT INTO `seminars` (employee_id, seminar_name, organizer, date_from, date_to, hours, certificate) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $employee_id,
            $seminar_data['seminar_name'],
            $seminar_data['organizer'],
            $seminar_data['date_from'],
            $seminar_data['date_to'],
            $seminar_data['hours'],
            $seminar_data['certificate']
        ]);
    }
}

// Add/update skill
function saveSkill($employee_id, $skill_data, $skill_id = null)
{
    $pdo = getDB();

    if ($skill_id) {
        // Update existing skill
        $sql = "UPDATE `skills` SET skill_name=?, proficiency_level=?, certification=? WHERE id=? AND employee_id=?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $skill_data['skill_name'],
            $skill_data['proficiency_level'],
            $skill_data['certification'],
            $skill_id,
            $employee_id
        ]);
    } else {
        // Insert new skill
        $sql = "INSERT INTO `skills` (employee_id, skill_name, proficiency_level, certification) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $employee_id,
            $skill_data['skill_name'],
            $skill_data['proficiency_level'],
            $skill_data['certification']
        ]);
    }
}

// Add/update work experience
function saveWorkExperience($employee_id, $work_data, $work_id = null)
{
    $pdo = getDB();

    if ($work_id) {
        // Update existing work experience
        $sql = "UPDATE `work_experience` SET company_name=?, position=?, date_from=?, date_to=?, is_current=?, years_experience=?, reference_name=?, reference_contact=? WHERE id=? AND employee_id=?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $work_data['company_name'],
            $work_data['position'],
            $work_data['date_from'],
            $work_data['date_to'],
            $work_data['is_current'],
            $work_data['years_experience'],
            $work_data['reference_name'],
            $work_data['reference_contact'],
            $work_id,
            $employee_id
        ]);
    } else {
        // Insert new work experience
        $sql = "INSERT INTO `work_experience` (employee_id, company_name, position, date_from, date_to, is_current, years_experience, reference_name, reference_contact) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $employee_id,
            $work_data['company_name'],
            $work_data['position'],
            $work_data['date_from'],
            $work_data['date_to'],
            $work_data['is_current'],
            $work_data['years_experience'],
            $work_data['reference_name'],
            $work_data['reference_contact']
        ]);
    }
}

// Add/update dependent
function saveDependent($employee_id, $data, $id = null) {
    $pdo = getDB();
    if ($id) {
        $stmt = $pdo->prepare("UPDATE family_dependents SET name=?, relationship=?, birth_date=?, contact_number=? WHERE id=? AND employee_id=?");
        return $stmt->execute([$data['name'], $data['relationship'], $data['birth_date'], $data['contact_number'], $id, $employee_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO family_dependents (employee_id, name, relationship, birth_date, contact_number) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$employee_id, $data['name'], $data['relationship'], $data['birth_date'], $data['contact_number']]);
    }
}

// Add/update salary history
function saveSalaryHistory($employee_id, $data, $id = null) {
    $pdo = getDB();
    if ($id) {
        $stmt = $pdo->prepare("UPDATE salary_history SET amount=?, effective_date=?, type=?, remarks=? WHERE id=? AND employee_id=?");
        return $stmt->execute([$data['amount'], $data['effective_date'], $data['type'], $data['remarks'], $id, $employee_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO salary_history (employee_id, amount, effective_date, type, remarks) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$employee_id, $data['amount'], $data['effective_date'], $data['type'], $data['remarks']]);
    }
}

// Add/update disciplinary case
function saveDisciplinaryCase($employee_id, $data, $id = null) {
    $pdo = getDB();
    if ($id) {
        $stmt = $pdo->prepare("UPDATE disciplinary_cases SET violation=?, description=?, action_taken=?, date_reported=?, date_closed=?, status=? WHERE id=? AND employee_id=?");
        return $stmt->execute([$data['violation'], $data['description'], $data['action_taken'], $data['date_reported'], $data['date_closed'], $data['status'], $id, $employee_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO disciplinary_cases (employee_id, violation, description, action_taken, date_reported, date_closed, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$employee_id, $data['violation'], $data['description'], $data['action_taken'], $data['date_reported'], $data['date_closed'], $data['status']]);
    }
}

// Add/update performance review
function savePerformanceReview($employee_id, $data, $id = null) {
    $pdo = getDB();
    if ($id) {
        $stmt = $pdo->prepare("UPDATE performance_reviews SET review_date=?, rating=?, evaluator=?, comments=? WHERE id=? AND employee_id=?");
        return $stmt->execute([$data['review_date'], $data['rating'], $data['evaluator'], $data['comments'], $id, $employee_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO performance_reviews (employee_id, review_date, rating, evaluator, comments) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$employee_id, $data['review_date'], $data['rating'], $data['evaluator'], $data['comments']]);
    }
}

// Delete emergency contact
function deleteEmergencyContact($contact_id, $employee_id)
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM `emergency_contacts` WHERE id = ? AND employee_id = ?");
        return $stmt->execute([$contact_id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete emergency contact error: " . $e->getMessage());
        return false;
    }
}

// Delete educational background
function deleteEducationalBackground($education_id, $employee_id)
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM `educational_background` WHERE id = ? AND employee_id = ?");
        return $stmt->execute([$education_id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete educational background error: " . $e->getMessage());
        return false;
    }
}

// Delete seminar
function deleteSeminar($seminar_id, $employee_id)
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM `seminars` WHERE id = ? AND employee_id = ?");
        return $stmt->execute([$seminar_id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete seminar error: " . $e->getMessage());
        return false;
    }
}

// Delete skill
function deleteSkill($skill_id, $employee_id)
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM `skills` WHERE id = ? AND employee_id = ?");
        return $stmt->execute([$skill_id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete skill error: " . $e->getMessage());
        return false;
    }
}

// Delete work experience
function deleteWorkExperience($work_id, $employee_id)
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM `work_experience` WHERE id = ? AND employee_id = ?");
        return $stmt->execute([$work_id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete work experience error: " . $e->getMessage());
        return false;
    }
}

// Delete dependent
function deleteDependent($id, $employee_id) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM family_dependents WHERE id=? AND employee_id=?");
        return $stmt->execute([$id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete dependent error: " . $e->getMessage());
        return false;
    }
}

// Delete salary history
function deleteSalaryHistory($id, $employee_id) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM salary_history WHERE id=? AND employee_id=?");
        return $stmt->execute([$id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete salary history error: " . $e->getMessage());
        return false;
    }
}

// Delete disciplinary case
function deleteDisciplinaryCase($id, $employee_id) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM disciplinary_cases WHERE id=? AND employee_id=?");
        return $stmt->execute([$id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete disciplinary case error: " . $e->getMessage());
        return false;
    }
}

// Delete performance review
function deletePerformanceReview($id, $employee_id) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM performance_reviews WHERE id=? AND employee_id=?");
        return $stmt->execute([$id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete performance review error: " . $e->getMessage());
        return false;
    }
}


// Get documents for employee
function getEmployeeDocuments($employee_id)
{
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM `employee_documents` WHERE employee_id = ? ORDER BY upload_date DESC");
    $stmt->execute([$employee_id]);
    return $stmt->fetchAll();
}

// Add/update employee document
function saveEmployeeDocument($employee_id, $doc_data, $doc_id = null)
{
    $pdo = getDB();

    if ($doc_id) {
        $sql = "UPDATE `employee_documents` SET document_name=?, document_type=? WHERE id=? AND employee_id=?";
        $params = [
            $doc_data['document_name'],
            $doc_data['document_type'],
            $doc_id,
            $employee_id
        ];
        
        if (isset($doc_data['file_path'])) {
             $sql = "UPDATE `employee_documents` SET document_name=?, document_type=?, file_path=? WHERE id=? AND employee_id=?";
             $params = [
                $doc_data['document_name'],
                $doc_data['document_type'],
                $doc_data['file_path'],
                $doc_id,
                $employee_id
            ];
        }

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);

    } else {
        $sql = "INSERT INTO `employee_documents` (employee_id, document_name, document_type, file_path) VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $employee_id,
            $doc_data['document_name'],
            $doc_data['document_type'],
            $doc_data['file_path']
        ]);
    }
}

// Delete employee document
function deleteEmployeeDocument($doc_id, $employee_id)
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("DELETE FROM `employee_documents` WHERE id = ? AND employee_id = ?");
        return $stmt->execute([$doc_id, $employee_id]);
    } catch (Exception $e) {
        error_log("Delete document error: " . $e->getMessage());
        return false;
    }
}

// Initialize database
initDatabase();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => ''];

    try {
        switch ($action) {
            case 'update_profile':
                $employee_id = $_POST['employee_id'] ?? '';
                if ($employee_id && updateEmployee($employee_id, $_POST)) {
                    // Handle photo upload
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                        $upload_result = handleFileUpload($_FILES['photo'], $employee_id, 'photo');
                        if ($upload_result['success']) {
                            $pdo = getDB();
                            $stmt = $pdo->prepare("UPDATE employees SET photo = ? WHERE id = ?");
                            $stmt->execute([$upload_result['filename'], $employee_id]);
                        }
                    }
                    // Handle resume upload
                    if (isset($_FILES['resume_file']) && $_FILES['resume_file']['error'] === UPLOAD_ERR_OK) {
                        $upload_result = handleFileUpload($_FILES['resume_file'], $employee_id, 'resume');
                        if ($upload_result['success']) {
                            $pdo = getDB();
                            $stmt = $pdo->prepare("UPDATE employees SET resume_file = ? WHERE id = ?");
                            $stmt->execute([$upload_result['filename'], $employee_id]);
                        }
                    }
                    // Handle diploma upload
                    if (isset($_FILES['diploma_file']) && $_FILES['diploma_file']['error'] === UPLOAD_ERR_OK) {
                        $upload_result = handleFileUpload($_FILES['diploma_file'], $employee_id, 'diploma');
                        if ($upload_result['success']) {
                            $pdo = getDB();
                            $stmt = $pdo->prepare("UPDATE employees SET diploma_file = ? WHERE id = ?");
                            $stmt->execute([$upload_result['filename'], $employee_id]);
                        }
                    }
                    $response = ['success' => true, 'message' => 'Profile updated successfully'];
                } else {
                    $response['message'] = 'Failed to update profile';
                }
                break;

            case 'save_contact':
                $employee_id = $_POST['employee_id'] ?? '';
                $contact_id = $_POST['contact_id'] ?? null;
                if ($employee_id && saveEmergencyContact($employee_id, $_POST, $contact_id)) {
                    $response = ['success' => true, 'message' => 'Contact saved successfully'];
                } else {
                    $response['message'] = 'Failed to save contact';
                }
                break;

            case 'save_education':
                $employee_id = $_POST['employee_id'] ?? '';
                $education_id = $_POST['education_id'] ?? null;
                $education_data = $_POST;

                // Handle diploma file upload for education
                if (isset($_FILES['diploma_file']) && $_FILES['diploma_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleFileUpload($_FILES['diploma_file'], $employee_id, 'education_diploma');
                    if ($upload_result['success']) {
                        $education_data['diploma_file'] = $upload_result['filename'];
                    }
                }

                if ($employee_id && saveEducationalBackground($employee_id, $education_data, $education_id)) {
                    $response = ['success' => true, 'message' => 'Educational background saved successfully'];
                } else {
                    $response['message'] = 'Failed to save educational background';
                }
                break;

            case 'save_seminar':
                $employee_id = $_POST['employee_id'] ?? '';
                $seminar_id = $_POST['seminar_id'] ?? null;
                $seminar_data = $_POST;

                // Handle certificate upload
                if (isset($_FILES['certificate']) && $_FILES['certificate']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleFileUpload($_FILES['certificate'], $employee_id, 'certificate');
                    if ($upload_result['success']) {
                        $seminar_data['certificate'] = $upload_result['filename'];
                    }
                }

                if ($employee_id && saveSeminar($employee_id, $seminar_data, $seminar_id)) {
                    $response = ['success' => true, 'message' => 'Seminar saved successfully'];
                } else {
                    $response['message'] = 'Failed to save seminar';
                }
                break;

            case 'save_skill':
                $employee_id = $_POST['employee_id'] ?? '';
                $skill_id = $_POST['skill_id'] ?? null;
                $skill_data = $_POST;

                // Handle certification upload
                if (isset($_FILES['certification']) && $_FILES['certification']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleFileUpload($_FILES['certification'], $employee_id, 'certification');
                    if ($upload_result['success']) {
                        $skill_data['certification'] = $upload_result['filename'];
                    }
                }

                if ($employee_id && saveSkill($employee_id, $skill_data, $skill_id)) {
                    $response = ['success' => true, 'message' => 'Skill saved successfully'];
                } else {
                    $response['message'] = 'Failed to save skill';
                }
                break;

            case 'save_work_experience':
                $employee_id = $_POST['employee_id'] ?? '';
                $work_id = $_POST['work_id'] ?? null;
                if ($employee_id && saveWorkExperience($employee_id, $_POST, $work_id)) {
                    $response = ['success' => true, 'message' => 'Work experience saved successfully'];
                } else {
                    $response['message'] = 'Failed to save work experience';
                }
                break;

            case 'save_dependent':
                $employee_id = $_POST['employee_id'] ?? '';
                $id = $_POST['dependent_id'] ?? null;
                if ($employee_id && saveDependent($employee_id, $_POST, $id)) {
                    $response = ['success' => true, 'message' => 'Dependent saved successfully'];
                } else {
                    $response['message'] = 'Failed to save dependent';
                }
                break;

            case 'save_salary':
                $employee_id = $_POST['employee_id'] ?? '';
                $id = $_POST['salary_id'] ?? null;
                if ($employee_id && saveSalaryHistory($employee_id, $_POST, $id)) {
                    $response = ['success' => true, 'message' => 'Salary history saved successfully'];
                } else {
                    $response['message'] = 'Failed to save salary history';
                }
                break;

            case 'save_disciplinary':
                $employee_id = $_POST['employee_id'] ?? '';
                $id = $_POST['disciplinary_id'] ?? null;
                if ($employee_id && saveDisciplinaryCase($employee_id, $_POST, $id)) {
                    $response = ['success' => true, 'message' => 'Disciplinary case saved successfully'];
                } else {
                    $response['message'] = 'Failed to save disciplinary case';
                }
                break;

            case 'save_performance':
                $employee_id = $_POST['employee_id'] ?? '';
                $id = $_POST['performance_id'] ?? null;
                if ($employee_id && savePerformanceReview($employee_id, $_POST, $id)) {
                    $response = ['success' => true, 'message' => 'Performance review saved successfully'];
                } else {
                    $response['message'] = 'Failed to save performance review';
                }
                break;

            case 'delete_contact':
                $contact_id = $_POST['contact_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($contact_id) && !empty($employee_id) && deleteEmergencyContact($contact_id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Contact deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete contact';
                }
                break;

            case 'delete_education':
                $education_id = $_POST['education_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($education_id) && !empty($employee_id) && deleteEducationalBackground($education_id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Educational background deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete educational background';
                }
                break;

            case 'delete_seminar':
                $seminar_id = $_POST['seminar_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($seminar_id) && !empty($employee_id) && deleteSeminar($seminar_id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Seminar deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete seminar';
                }
                break;

            case 'delete_skill':
                $skill_id = $_POST['skill_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($skill_id) && !empty($employee_id) && deleteSkill($skill_id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Skill deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete skill';
                }
                break;

            case 'delete_work_experience':
                $work_id = $_POST['work_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($work_id) && !empty($employee_id) && deleteWorkExperience($work_id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Work experience deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete work experience';
                }
                break;

            case 'delete_dependent':
                $id = $_POST['dependent_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($id) && !empty($employee_id) && deleteDependent($id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Dependent deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete dependent';
                }
                break;

             case 'delete_salary':
                $id = $_POST['salary_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($id) && !empty($employee_id) && deleteSalaryHistory($id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Salary history deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete salary history';
                }
                break;

             case 'delete_disciplinary':
                $id = $_POST['disciplinary_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($id) && !empty($employee_id) && deleteDisciplinaryCase($id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Disciplinary case deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete disciplinary case';
                }
                break;

             case 'delete_performance':
                $id = $_POST['performance_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($id) && !empty($employee_id) && deletePerformanceReview($id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Performance review deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete performance review';
                }
                break;

            case 'get_employee_details':
                $employee_id = $_POST['employee_id'] ?? '';
                if ($employee_id) {
                    $emp = getEmployee($employee_id);
                    if ($emp) {
                        $response = [
                            'success' => true,
                            'employee' => $emp,
                            'contacts' => getEmergencyContacts($employee_id),
                            'education' => getEducationalBackground($employee_id),
                            'seminars' => getSeminars($employee_id),
                            'skills' => getSkills($employee_id),
                            'work' => getWorkExperience($employee_id),
                            'documents' => getEmployeeDocuments($employee_id),
                            'dependents' => getDependents($employee_id),
                            'salary_history' => getSalaryHistory($employee_id),
                            'disciplinary' => getDisciplinaryCases($employee_id),
                            'subordinates' => [], // Placeholder for now
                            'performance' => getPerformanceReviews($employee_id)
                        ];
                    } else {
                        $response['message'] = 'Employee not found';
                    }
                } else {
                    $response['message'] = 'Invalid ID';
                }
                break;

            case 'save_document':
                $employee_id = $_POST['employee_id'] ?? '';
                $doc_id = $_POST['document_id'] ?? null;
                $doc_data = $_POST;

                // Handle document upload
                if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_result = handleFileUpload($_FILES['document_file'], $employee_id, 'document');
                    if ($upload_result['success']) {
                        $doc_data['file_path'] = $upload_result['filename'];
                    } else {
                        throw new Exception($upload_result['message']);
                    }
                } else if (!$doc_id) {
                     throw new Exception('File is required for new documents');
                }

                if ($employee_id && saveEmployeeDocument($employee_id, $doc_data, $doc_id)) {
                    $response = ['success' => true, 'message' => 'Document saved successfully'];
                } else {
                    $response['message'] = 'Failed to save document';
                }
                break;

            case 'delete_document':
                $doc_id = $_POST['document_id'] ?? '';
                $employee_id = $_POST['employee_id'] ?? '';
                if (!empty($doc_id) && !empty($employee_id) && deleteEmployeeDocument($doc_id, $employee_id)) {
                    $response = ['success' => true, 'message' => 'Document deleted successfully'];
                } else {
                    $response['message'] = 'Failed to delete document';
                }
                break;

            case 'import_hr1_employees':
                $raw_data = $_POST['employees'] ?? '[]';
                $employees_to_import = json_decode($raw_data, true);
                $imported_count = 0;
                $skipped_count = 0;
                
                if (json_last_error() === JSON_ERROR_NONE && is_array($employees_to_import)) {
                    $pdo = getDB();
                    // Prepare statements
                    $stmtCheck = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
                    $stmtInsert = $pdo->prepare("INSERT INTO employees (name, email, job_title, department, status, employee_id) VALUES (?, ?, ?, ?, 'Active', ?)");
                    
                    foreach ($employees_to_import as $emp) {
                        $email = $emp['email'] ?? '';
                        if (empty($email)) continue; // Skip if no email to identify
                        
                        // Check duplicate
                        $stmtCheck->execute([$email]);
                        if ($stmtCheck->fetch()) {
                            $skipped_count++;
                            continue; 
                        }
                        
                        $name = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                        $job_title = $emp['position'] ?? '';
                        $dept = $emp['department'] ?? '';
                        $hr1_id = $emp['id'] ?? rand(1000,9999);
                        $emp_id = 'IMP-' . str_pad($hr1_id, 4, '0', STR_PAD_LEFT); // Prefix IMP for imported
                        
                        // Note: Ignoring avatar/photo for now as it requires file downloading/handling logic not present
                        
                        if ($stmtInsert->execute([$name, $email, $job_title, $dept, $emp_id])) {
                            $imported_count++;
                        }
                    }
                    $response = ['success' => true, 'message' => "Import complete. Imported: $imported_count, Skipped (Duplicate): $skipped_count"];
                } else {
                    $response['message'] = 'Invalid employee data format';
                }
                break;

            default:
                $response['message'] = 'Invalid action';
                break;
        }
    } catch (Exception $e) {
        error_log("Form submission error: " . $e->getMessage());
        $response['message'] = 'An error occurred: ' . $e->getMessage();
    }

    // Always return JSON for AJAX requests
    ob_clean(); // Clean any previous output (HTML errors, warnings, etc.)
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Include sidebar and responsive files (View Logic)
include '../includes/sidebar.php';
include '../responsive/responsive.php';

// Get departments for filter
$pdo = getDB();
$depts_result = $pdo->query("SELECT DISTINCT department FROM employees ORDER BY department");
$depts = [];
while ($row = $depts_result->fetch()) {
    $depts[] = $row['department'];
}

// Handle Filters
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search_query = $_GET['search'] ?? '';
$employees = getEmployees($department_filter, $status_filter, $search_query);

// Get specific employee for editing
$edit_employee = null;
$emergency_contacts = [];
$educational_background = [];
$seminars = [];
$skills = [];
$work_experience = [];
if (isset($_GET['edit'])) {
    $edit_employee = getEmployee($_GET['edit']);
    if ($edit_employee) {
        $emergency_contacts = getEmergencyContacts($_GET['edit']);
        $educational_background = getEducationalBackground($_GET['edit']);
        $seminars = getSeminars($_GET['edit']);
        $skills = getSkills($_GET['edit']);
        $work_experience = getWorkExperience($_GET['edit']);
        $documents = getEmployeeDocuments($_GET['edit']);
        $dependents = getDependents($_GET['edit']);
        $salary_history = getSalaryHistory($_GET['edit']);
        $disciplinary = getDisciplinaryCases($_GET['edit']);
        $performance = getPerformanceReviews($_GET['edit']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR4 - Employee Profiles</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #27ae60;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
            --border-color: #dee2e6;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: var(--dark-text);
        }
        
        /* Dark Mode Styles */
        body.dark-mode {
            background-color: #1a202c;
            color: #f8f9fa;
            --dark-text: #f8f9fa;
            --light-bg: #2d3748;
            --border-color: #4a5568;
        }

        body.dark-mode .card {
            background-color: #2d3748;
            border-color: #4a5568;
        }

        body.dark-mode .card-header {
            background-color: #2d3748;
            border-bottom-color: #4a5568;
            color: #f8f9fa;
        }
        
        body.dark-mode .form-control, 
        body.dark-mode .form-select {
            background-color: #4a5568;
            border-color: #718096;
            color: white;
        }
        
        body.dark-mode .form-control:focus, 
        body.dark-mode .form-select:focus {
            background-color: #4a5568; 
            color: white;
        }

        body.dark-mode .table {
            --bs-table-color: #f8f9fa;
            --bs-table-bg: transparent;
            --bs-table-border-color: #4a5568;
            --bs-table-hover-bg: rgba(255, 255, 255, 0.05);
            --bs-table-hover-color: #f8f9fa;
            color: #f8f9fa;
            border-color: #4a5568;
        }
        
        body.dark-mode .table th,
        body.dark-mode .table td {
            border-color: #4a5568;
            color: #f8f9fa;
        }
        
        body.dark-mode .filter-section {
            background-color: #2d3748;
        }
        
        body.dark-mode .modal-content {
            background-color: #2d3748;
            color: #f8f9fa;
        }
        
        body.dark-mode .modal-header,
        body.dark-mode .modal-footer {
            border-color: #4a5568;
        }
        
        body.dark-mode .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
        
        body.dark-mode .nav-tabs .nav-link.active {
            background-color: #2d3748;
            border-color: #4a5568 #4a5568 #2d3748;
            color: #f8f9fa;
        }
        
        body.dark-mode .nav-tabs .nav-link {
            color: #a0aec0;
        }
        
        body.dark-mode .nav-tabs {
            border-bottom-color: #4a5568;
        }
        
        body.dark-mode .text-muted {
            color: #a0aec0 !important;
        }
        
        body.dark-mode .list-group-item {
            background-color: #2d3748;
            color: #f8f9fa;
            border-color: #4a5568;
        }
        
        body.dark-mode label,
        body.dark-mode .form-label,
        body.dark-mode h1,
        body.dark-mode h2,
        body.dark-mode h3,
        body.dark-mode h4,
        body.dark-mode h5,
        body.dark-mode h6 {
            color: #f8f9fa;
        }

        body.dark-mode .input-group-text {
            background-color: #4a5568;
            border-color: #718096;
            color: #f8f9fa;
        }

        body.dark-mode .form-check-input {
            background-color: #4a5568;
            border-color: #718096;
        }
        
        body.dark-mode .form-check-input:checked {
            background-color: var(--secondary);
            border-color: var(--secondary);
        }

        /* Dark mode scrollbars */
        body.dark-mode ::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        body.dark-mode ::-webkit-scrollbar-track {
            background: #2d3748; 
        }

        body.dark-mode ::-webkit-scrollbar-thumb {
            background: #4a5568; 
            border-radius: 6px;
        }

        body.dark-mode ::-webkit-scrollbar-thumb:hover {
            background: #718096; 
        }
        
        body.dark-mode .dropdown-menu {
            background-color: #2d3748;
            border-color: #4a5568;
        }
        
        body.dark-mode .dropdown-item {
            color: #f8f9fa;
        }
        
        body.dark-mode .dropdown-item:hover,
        body.dark-mode .dropdown-item:focus {
            background-color: #4a5568;
            color: #ffffff;
        }
        
        /* Fix placeholder text color in dark mode */
        body.dark-mode ::placeholder {
            color: #a0aec0;
            opacity: 1;
        }
        
        body.dark-mode :-ms-input-placeholder {
            color: #a0aec0;
        }
        
        body.dark-mode ::-ms-input-placeholder {
            color: #a0aec0;
        }
        
        body.dark-mode .fw-bold {
            color: #f8f9fa;
        }
        
        /* Dark mode buttons */
        body.dark-mode .btn-outline-primary {
            color: #63b3ed;
            border-color: #63b3ed;
        }
        
        body.dark-mode .btn-outline-primary:hover {
            background-color: #63b3ed;
            color: #1a202c;
        }
        
        body.dark-mode .btn-outline-info {
            color: #4fd1c5;
            border-color: #4fd1c5;
        }
        
        body.dark-mode .btn-outline-info:hover {
            background-color: #4fd1c5;
            color: #1a202c;
        }
        
        body.dark-mode .btn-outline-danger {
            color: #fc8181;
            border-color: #fc8181;
        }
        
        body.dark-mode .btn-outline-danger:hover {
            background-color: #fc8181;
            color: #1a202c;
        }

        .navbar-brand {
            font-weight: 600;
        }

        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
            border-radius: 0.5rem;
        }

        .card-header {
            background-color: white;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            padding: 1rem 1.25rem;
        }

        .employee-photo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--secondary);
        }

        .status-badge {
            padding: 0.35em 0.65em;
            font-size: 0.75em;
        }

        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--dark-text);
        }

        .btn-primary {
            background-color: var(--secondary);
            border-color: var(--secondary);
        }

        .btn-primary:hover {
            background-color: #2980b9;
            border-color: #2980b9;
        }

        .filter-section {
            background-color: white;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .emergency-contact-card {
            border-left: 4px solid var(--secondary);
        }

        .primary-contact {
            border-left-color: var(--success);
        }

        .photo-placeholder {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--secondary);
        }
        
        body.dark-mode .photo-placeholder {
            background-color: #4a5568;
            color: #a0aec0;
        }

        .file-download-btn {
            font-size: 0.8rem;
            padding: 0.2rem 0.5rem;
        }

        .section-title {
            border-left: 4px solid var(--secondary);
            padding-left: 1rem;
            margin: 1.5rem 0 1rem 0;
            font-weight: 600;
        }

        .form-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        
        body.dark-mode .form-container {
            background-color: #2d3748;
        }

        .alert-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            min-width: 300px;
        }
        
        #view_photo_container img, #view_photo_container .photo-placeholder {
            width: 120px;
            height: 120px;
        }
        
        .auto-responsive-table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        /* MOBILE RESPONSIVE FIXES */
        @media (max-width: 768px) {
            /* Fix Gemini Card Layout */
            [style*="columns: 2"] {
                columns: 1 !important;
                width: 100% !important;
            }
            
            /* Fix Long Header Text Wrapping */
            .text-xs.font-weight-bold.text-uppercase {
                white-space: normal !important;
                line-height: 1.2 !important;
                margin-bottom: 0.5rem !important;
            }

            /* Adjust Container Margins */
            .container.mt-4 {
                margin-top: 20px !important; /* Reduce gap */
                padding-left: 10px !important;
                padding-right: 10px !important;
            }

            /* Generic Navbar/Header Fixes */
            nav.navbar, header, .top-bar {
                flex-wrap: wrap !important;
                height: auto !important;
                padding: 10px !important;
            }
            
            /* Stack header elements if they are flex */
            nav.navbar > div, header > div {
                flex-wrap: wrap !important;
            }
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <?php if (function_exists('getNavbar')) echo getNavbar(); ?>

    <div class="container mt-4" style="margin-top: 60px !important;">
        <!-- Alert Container for AJAX messages -->
        <div class="alert-container"></div>

        <!-- Messages -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type'] === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
        <?php endif; ?>

        <!-- Google Gemini Highlights -->
        <div class="card mb-4 border-0 shadow-sm" style="border-left: 5px solid #4e73df !important; background: linear-gradient(to right, #ffffff, #f8f9fc);">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                            Google Gemini (Good for document + data analysis)
                        </div>
                        <div class="h5 mb-2 font-weight-bold text-gray-800">Google (Gemini AI)</div>
                        <div class="text-muted small">
                            <strong class="d-block mb-1">Why it’s useful:</strong>
                            <ul class="mb-0" style="columns: 2;">
                                <li><i class="fas fa-file-contract text-primary me-2"></i>Analyze uploaded contracts</li>
                                <li><i class="fas fa-file-pdf text-danger me-2"></i>Extract information from PDFs</li>
                                <li><i class="fas fa-exclamation-triangle text-warning me-2"></i>Detect missing fields in employee records</li>
                                <li><i class="fas fa-check-double text-success me-2"></i>Check inconsistencies in employee data</li>
                            </ul>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary btn-sm" onclick="runGeminiAudit()">
                                <i class="fas fa-magic me-2"></i>Run Smart Audit
                            </button>
                            <small class="text-muted ms-2 fst-italic">Powered by Gemini AI Logic</small>
                        </div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-robot fa-3x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gemini Audit Modal -->
        <div class="modal fade" id="geminiAuditModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-gradient-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-robot me-2"></i>AI-Powered Compliance Audit</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="auditLoading" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-3 text-muted">Analyzing employee records and documents...</p>
                        </div>
                        <div id="auditResults" style="display:none;">
                            <div class="alert alert-info d-flex align-items-center mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                <div>
                                    Analysis Complete. Found <strong id="issueCount">0</strong> potential issues across <strong id="empCount">0</strong> active profiles.
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Employee</th>
                                            <th>Detected Issues</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="auditTableBody">
                                        <!-- content -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div id="auditEmpty" style="display:none;" class="text-center py-5">
                            <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                            <h5>All Clear!</h5>
                            <p class="text-muted">No missing documents or data inconsistencies found.</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function runGeminiAudit() {
            const modal = new bootstrap.Modal(document.getElementById('geminiAuditModal'));
            modal.show();
            
            document.getElementById('auditLoading').style.display = 'block';
            document.getElementById('auditResults').style.display = 'none';
            document.getElementById('auditEmpty').style.display = 'none';

            fetch('../api/gemini_audit.php') // Adjust path if needed
                .then(response => response.json())
                .then(data => {
                    document.getElementById('auditLoading').style.display = 'none';
                    
                    if (data.success) {
                        if (data.issues.length > 0) {
                            document.getElementById('auditResults').style.display = 'block';
                            document.getElementById('issueCount').textContent = data.stats.issues_found;
                            document.getElementById('empCount').textContent = data.stats.analyzed;
                            
                            const tbody = document.getElementById('auditTableBody');
                            tbody.innerHTML = '';
                            
                            data.issues.forEach(issue => {
                                let issuesHtml = '';
                                issue.findings.forEach(f => {
                                    let badgeClass = f.severity === 'critical' ? 'bg-danger' : (f.severity === 'high' ? 'bg-warning text-dark' : 'bg-info text-dark');
                                    issuesHtml += `<div class="mb-1"><span class="badge ${badgeClass} me-2">${f.type}</span>${f.msg}</div>`;
                                });

                                const tr = document.createElement('tr');
                                tr.innerHTML = `
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="fw-bold text-dark">${issue.name}</div>
                                        </div>
                                        <small class="text-muted">${issue.employee_id} | ${issue.department}</small>
                                    </td>
                                    <td>${issuesHtml}</td>
                                    <td class="text-end">
                                        <a href="?edit=${issue.id}" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i> Fix</a>
                                    </td>
                                `;
                                tbody.appendChild(tr);
                            });
                        } else {
                            document.getElementById('auditEmpty').style.display = 'block';
                        }
                    } else {
                        alert('Error running audit: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('auditLoading').style.display = 'none';
                    alert('An error occurred while running the audit.');
                });
        }
        </script>

        <div class="row">
            <!-- Employee List -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Employee Directory</span>
                        <div>
                            <span class="badge bg-primary"><?php echo count($employees); ?> Employees</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Filters -->
                        <div class="filter-section mb-3">
                        <!-- Filters & Search -->
                        <div class="filter-section mb-3">
                            <form method="GET" class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                        <input type="text" name="search" class="form-control" placeholder="Search by name or ID..." value="<?php echo htmlspecialchars($search_query); ?>">
                                        <button class="btn btn-outline-secondary" type="submit">Go</button>
                                    </div>
                                </div>
                                <div class="col-md-3"> <!-- Changed from col-md-3 to col-md-3 -->
                                    <select name="department" class="form-select" onchange="this.form.submit()">
                                        <option value="">All Departments</option>
                                        <?php
                                        foreach ($depts as $dept) {
                                            $selected = $department_filter === $dept ? 'selected' : '';
                                            echo "<option value=\"$dept\" $selected>$dept</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-2"> <!-- Reduced width -->
                                    <select name="status" class="form-select" onchange="this.form.submit()">
                                        <option value="">All Statuses</option>
                                        <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary w-100" title="Clear Filters"><i class="fas fa-times"></i></a>
                                </div>
                                <div class="col-md-2">
                                     <button type="button" class="btn btn-outline-primary w-100" id="fetchHr1Btn">
                                        <i class="fas fa-cloud-download-alt me-1"></i> Fetch HR1
                                    </button>
                                </div>
                            </form>
                        </div>
                        </div>

                        <!-- Employee Table -->
                        <div class="table-responsive">
                            <div class="auto-responsive-table-wrapper">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Department</th>
                                            <th>Position</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($employees as $employee): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($employee['photo']) && file_exists(UPLOAD_DIR . $employee['photo'])): ?>
                                                            <img src="<?php echo UPLOAD_DIR . $employee['photo']; ?>" class="employee-photo me-3" alt="Photo">
                                                        <?php else: ?>
                                                            <div class="photo-placeholder me-3">
                                                                <i class="fas fa-user text-muted fa-2x"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="fw-bold"><?php echo htmlspecialchars($employee['name']); ?></div>
                                                            <small class="text-muted"><?php echo htmlspecialchars($employee['employee_id'] ?? 'EMP' . str_pad($employee['id'], 4, '0', STR_PAD_LEFT)); ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($employee['department']); ?></td>
                                                <td><?php echo htmlspecialchars($employee['job_title']); ?></td>
                                                <td>
                                                    <span class="badge status-badge <?php echo $employee['status'] === 'Active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo $employee['status']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-info view-employee me-1" data-id="<?php echo $employee['id']; ?>">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <a href="?edit=<?php echo $employee['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Edit Profile Modal (Previously Sidebar) -->
            <?php if ($edit_employee): ?>
            <div class="modal fade" id="editEmployeeModal" tabindex="-1" data-bs-backdrop="static">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <?php 
                            $is_imported = (strpos($edit_employee['employee_id'] ?? '', 'IMP-') === 0);
                            $disabled_attr = $is_imported ? 'disabled' : '';
                        ?>
                        <div class="modal-header">
                            <h5 class="modal-title text-primary"><i class="fas fa-user-edit me-2"></i>Edit Profile</h5>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn-close"></a>
                        </div>
                        <div class="modal-body p-0">
                            <div class="card shadow-none border-0">
        
        <!-- Profile Header -->
        <div class="card-body bg-light border-bottom text-center py-4">
            <div class="position-relative d-inline-block mb-3">
                <?php if (!empty($edit_employee['photo']) && file_exists(UPLOAD_DIR . $edit_employee['photo'])): ?>
                    <img src="<?php echo UPLOAD_DIR . $edit_employee['photo']; ?>" class="rounded-circle shadow-sm border border-3 border-white" style="width: 100px; height: 100px; object-fit: cover;">
                <?php else: ?>
                    <div class="rounded-circle shadow-sm border border-3 border-white bg-white d-flex align-items-center justify-content-center mx-auto" style="width: 100px; height: 100px;">
                        <i class="fas fa-user fa-3x text-secondary"></i>
                    </div>
                <?php endif; ?>
                <span class="position-absolute bottom-0 end-0 badge rounded-pill bg-<?php echo $edit_employee['status'] === 'Active' ? 'success' : 'secondary'; ?> border border-white">
                    <?php echo $edit_employee['status']; ?>
                </span>
            </div>
            <h5 class="mb-1 fw-bold"><?php echo htmlspecialchars($edit_employee['name']); ?></h5>
            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($edit_employee['job_title']); ?> | <?php echo htmlspecialchars($edit_employee['department']); ?></p>
            <p class="text-muted small mb-0 font-monospace"><?php echo htmlspecialchars($edit_employee['employee_id'] ?? 'EMP' . str_pad($edit_employee['id'], 4, '0', STR_PAD_LEFT)); ?></p>
        </div>

        <!-- Navigation Tabs -->
        <div class="card-header bg-white p-0 border-bottom-0">
            <ul class="nav nav-tabs nav-fill card-header-tabs m-0" id="editTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_personal" data-bs-toggle="tab"><i class="fas fa-id-card me-2"></i>Personal</button></li>
                <li class="nav-item"><button class="nav-link border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_employment" data-bs-toggle="tab"><i class="fas fa-briefcase me-2"></i>Employ.</button></li>
                <li class="nav-item"><button class="nav-link border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_compensation" data-bs-toggle="tab"><i class="fas fa-money-bill-wave me-2"></i>Compens.</button></li>
                <li class="nav-item"><button class="nav-link border-0 border-bottom rounded-0 py-3" data-bs-target="#tab_docs" data-bs-toggle="tab"><i class="fas fa-folder-open me-2"></i>201 Files</button></li>
            </ul>
        </div>

        <div class="card-body p-0">
            <div class="tab-content">
                <!-- PERSONAL TAB -->
                <div class="tab-pane fade show active" id="tab_personal">
                    <div class="p-4">
                        <?php if ($is_imported): ?>
                            <div class="alert alert-warning mb-4">
                                <i class="fas fa-lock me-2"></i>This is an imported record. Personal and employment details cannot be edited.
                            </div>
                        <?php endif; ?>
                        <form method="POST" enctype="multipart/form-data" id="profileForm">
                            <input type="hidden" name="action" value="update_profile">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">Basic Information</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-12">
                                    <label class="form-label small">Full Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($edit_employee['name']); ?>" required <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Date of Birth</label>
                                    <input type="date" name="birth_date" class="form-control" value="<?php echo htmlspecialchars($edit_employee['birth_date'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Gender</label>
                                    <select name="gender" class="form-select" <?php echo $disabled_attr; ?>>
                                        <option value="Male" <?php echo ($edit_employee['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($edit_employee['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Civil Status</label>
                                    <select name="civil_status" class="form-select" <?php echo $disabled_attr; ?>>
                                        <option value="Single" <?php echo ($edit_employee['civil_status'] ?? '') === 'Single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="Married" <?php echo ($edit_employee['civil_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="Widowed" <?php echo ($edit_employee['civil_status'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Nationality</label>
                                    <input type="text" name="nationality" class="form-control" value="<?php echo htmlspecialchars($edit_employee['nationality'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Photo</label>
                                    <input type="file" name="photo" class="form-control" accept="image/*" <?php echo $disabled_attr; ?>>
                                </div>
                            </div>
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">Contact Details</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit_employee['email'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($edit_employee['phone'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Address</label>
                                    <textarea name="address" class="form-control" rows="2" <?php echo $disabled_attr; ?>><?php echo htmlspecialchars($edit_employee['address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            
                            <?php if (!$is_imported): ?>
                            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save Changes</button>
                            <?php endif; ?>
                        </form>
                        
                        <hr class="my-4">
                        
                        <!-- Dependents Section -->
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3 d-flex justify-content-between align-items-center">
                            Dependents
                        </h6>
                        <div class="list-group mb-3">
                            <?php foreach ($dependents as $dep): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                    <div>
                                        <strong><?php echo htmlspecialchars($dep['name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($dep['relationship']); ?> | <?php echo htmlspecialchars($dep['birth_date']); ?></small>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger delete-dependent" data-dependent-id="<?php echo $dep['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="dependentForm" class="card bg-light border-0 p-3">
                            <input type="hidden" name="action" value="save_dependent">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            <div class="row g-2">
                                <div class="col-6"><input type="text" name="name" class="form-control form-control-sm" placeholder="Name" required></div>
                                <div class="col-6"><input type="text" name="relationship" class="form-control form-control-sm" placeholder="Relationship" required></div>
                                <div class="col-6"><input type="date" name="birth_date" class="form-control form-control-sm"></div>
                                <div class="col-6"><input type="text" name="contact_number" class="form-control form-control-sm" placeholder="Contact #"></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add Dependent</button></div>
                            </div>
                        </form>
                        
                         <hr class="my-4">

                        <!-- Emergency Contacts -->
                         <h6 class="text-uppercase text-secondary small fw-bold mb-3">Emergency Contacts</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($emergency_contacts as $contact): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                    <div>
                                        <strong><?php echo htmlspecialchars($contact['contact_name']); ?></strong>
                                        <span class="badge bg-light text-dark border ms-1"><?php echo htmlspecialchars($contact['relationship']); ?></span>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($contact['phone']); ?></small>
                                    </div>
                                    <button class="btn btn-sm btn-outline-danger delete-contact" data-contact-id="<?php echo $contact['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="contactForm" class="card bg-light border-0 p-3">
                             <input type="hidden" name="action" value="save_contact">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             <div class="row g-2">
                                <div class="col-6"><input type="text" name="contact_name" class="form-control form-control-sm" placeholder="Name" required></div>
                                <div class="col-6"><input type="text" name="relationship" class="form-control form-control-sm" placeholder="Relationship" required></div>
                                <div class="col-12"><input type="text" name="phone" class="form-control form-control-sm" placeholder="Phone" required></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add Emergency Contact</button></div>
                             </div>
                        </form>
                    </div>
                </div>

                <!-- EMPLOYMENT TAB -->
                <div class="tab-pane fade" id="tab_employment">
                    <div class="p-4">
                        <?php if ($is_imported): ?>
                            <div class="alert alert-warning mb-4">
                                <i class="fas fa-lock me-2"></i>This is an imported record. Personal and employment details cannot be edited.
                            </div>
                        <?php endif; ?>
                        <form method="POST" id="employmentForm" enctype="multipart/form-data">
                             <input type="hidden" name="action" value="update_profile">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             
                             <h6 class="text-uppercase text-secondary small fw-bold mb-3">Employment Details</h6>
                             <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small">Date Hired</label>
                                    <input type="date" name="date_hired" class="form-control" value="<?php echo htmlspecialchars($edit_employee['date_hired'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Date Regularized</label>
                                    <input type="date" name="date_regularized" class="form-control" value="<?php echo htmlspecialchars($edit_employee['date_regularized'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Status</label>
                                    <select name="status" class="form-select" <?php echo $disabled_attr; ?>>
                                        <option value="Active" <?php echo $edit_employee['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $edit_employee['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Contract Type</label>
                                    <select name="contract" class="form-select" <?php echo $disabled_attr; ?>>
                                        <option value="Regular" <?php echo ($edit_employee['contract'] ?? '') === 'Regular' ? 'selected' : ''; ?>>Regular</option>
                                        <option value="Probationary" <?php echo ($edit_employee['contract'] ?? '') === 'Probationary' ? 'selected' : ''; ?>>Probationary</option>
                                        <option value="Contract" <?php echo ($edit_employee['contract'] ?? '') === 'Contract' ? 'selected' : ''; ?>>Contract</option>
                                        <option value="Project-Based" <?php echo ($edit_employee['contract'] ?? '') === 'Project-Based' ? 'selected' : ''; ?>>Project-Based</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Department</label>
                                    <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($edit_employee['department']); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Position</label>
                                    <input type="text" name="job_title" class="form-control" value="<?php echo htmlspecialchars($edit_employee['job_title']); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Manager/Supervisor</label>
                                    <input type="text" name="manager" class="form-control" value="<?php echo htmlspecialchars($edit_employee['manager'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Work Schedule</label>
                                    <input type="text" name="work_schedule" class="form-control" value="<?php echo htmlspecialchars($edit_employee['work_schedule'] ?? ''); ?>" <?php echo $disabled_attr; ?>>
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Job Description</label>
                                    <textarea name="job_description" class="form-control" rows="3" <?php echo $disabled_attr; ?>><?php echo htmlspecialchars($edit_employee['job_description'] ?? ''); ?></textarea>
                                </div>
                             </div>
                             
                             <h6 class="text-uppercase text-secondary small fw-bold mb-3">Files</h6>
                             <div class="mb-3">
                                 <label class="form-label small">Resume</label>
                                 <input type="file" name="resume_file" class="form-control form-control-sm" <?php echo $disabled_attr; ?>>
                                 <?php if (!empty($edit_employee['resume_file'])): ?>
                                     <small><a href="<?php echo UPLOAD_DIR . $edit_employee['resume_file']; ?>" target="_blank">View Current</a></small>
                                 <?php endif; ?>
                             </div>
                             
                             <?php if (!$is_imported): ?>
                             <button type="submit" class="btn btn-primary w-100"><i class="fas fa-save me-1"></i> Save Changes</button>
                             <?php endif; ?>
                        </form>
                        
                        <hr class="my-4">
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3">Work History</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($work_experience as $work): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($work['position']); ?></h6>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($work['company_name']); ?></small>
                                            <small class="text-muted"><?php echo htmlspecialchars($work['date_from']); ?> - <?php echo $work['is_current'] ? 'Present' : htmlspecialchars($work['date_to']); ?></small>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger delete-work" data-work-id="<?php echo $work['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <form method="POST" id="workForm" class="card bg-light border-0 p-3">
                             <input type="hidden" name="action" value="save_work_experience">
                             <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                             <div class="row g-2">
                                <div class="col-6"><input type="text" name="company_name" class="form-control form-control-sm" placeholder="Company" required></div>
                                <div class="col-6"><input type="text" name="position" class="form-control form-control-sm" placeholder="Position" required></div>
                                <div class="col-6"><input type="date" name="date_from" class="form-control form-control-sm" required></div>
                                <div class="col-6"><input type="date" name="date_to" class="form-control form-control-sm"></div>
                                <div class="col-12"><div class="form-check"><input type="checkbox" name="is_current" value="1" class="form-check-input" id="is_current"><label class="form-check-label small" for="is_current">Current Job</label></div></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Add History</button></div>
                             </div>
                        </form>
                    </div>
                </div>
                
                <!-- COMPENSATION TAB -->
                <div class="tab-pane fade" id="tab_compensation">
                    <div class="p-4">
                        <form method="POST" id="compForm">
                            <input type="hidden" name="action" value="update_profile">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="fas fa-info-circle me-1"></i> Compensation details are managed in the Compensation Module or provided via verified 201 file documents.
                            </div>

                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">Comp & Ben</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Basic Salary</label>
                                    <input type="text" name="salary" class="form-control bg-light" value="<?php echo htmlspecialchars($edit_employee['salary']); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Pay Grade</label>
                                    <input type="text" name="pay_grade" class="form-control bg-light" value="<?php echo htmlspecialchars($edit_employee['pay_grade'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Bank Name</label>
                                    <input type="text" name="bank_name" class="form-control bg-light" value="<?php echo htmlspecialchars($edit_employee['bank_name'] ?? ''); ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Account No.</label>
                                    <input type="text" name="bank_account_no" class="form-control bg-light" value="<?php echo htmlspecialchars($edit_employee['bank_account_no'] ?? ''); ?>" readonly>
                                </div>
                            </div>
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mt-4 mb-3">Government IDs</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6"><label class="form-label small">SSS</label><input type="text" name="sss_no" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($edit_employee['sss_no'] ?? ''); ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label small">PhilHealth</label><input type="text" name="philhealth_no" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($edit_employee['philhealth_no'] ?? ''); ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label small">Pag-IBIG</label><input type="text" name="pagibig_no" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($edit_employee['pagibig_no'] ?? ''); ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label small">TIN</label><input type="text" name="tin_no" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($edit_employee['tin_no'] ?? ''); ?>" readonly></div>
                            </div>
                            
                            <h6 class="text-uppercase text-secondary small fw-bold mb-3">HMO / Benefits</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6"><label class="form-label small">HMO Provider</label><input type="text" name="hmo_provider" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($edit_employee['hmo_provider'] ?? ''); ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label small">HMO Number</label><input type="text" name="hmo_number" class="form-control form-control-sm bg-light" value="<?php echo htmlspecialchars($edit_employee['hmo_number'] ?? ''); ?>" readonly></div>
                            </div>
                        </form>
                        
                        <hr class="my-4">
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3">Salary History</h6>
                        <div class="list-group mb-3">
                             <?php foreach ($salary_history as $hist): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-2">
                                    <div>
                                        <strong><?php echo number_format($hist['amount'], 2); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($hist['type']); ?> | <?php echo htmlspecialchars($hist['effective_date']); ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>



                <!-- 201 FILES TAB -->
                <div class="tab-pane fade" id="tab_docs">
                    <div class="p-4">
                        <h6 class="text-uppercase text-secondary small fw-bold mb-3">201 Files & Certificates</h6>
                        <div class="list-group mb-3">
                            <?php foreach ($documents as $doc): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3 text-secondary"><i class="fas fa-file-alt fa-2x"></i></div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($doc['document_name']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($doc['document_type']); ?> | <?php echo date('M d, Y', strtotime($doc['upload_date'])); ?></small>
                                            </div>
                                        </div>
                                        <div class="btn-group">
                                            <a href="<?php echo UPLOAD_DIR . $doc['file_path']; ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></a>
                                            <button class="btn btn-sm btn-outline-danger delete-document" data-document-id="<?php echo $doc['id']; ?>" data-employee-id="<?php echo $edit_employee['id']; ?>"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        


                        <form method="POST" enctype="multipart/form-data" id="documentForm" class="card bg-light border-0 p-3">
                            <input type="hidden" name="action" value="save_document">
                            <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                            <div class="row g-2">
                                <div class="col-12"><input type="text" name="document_name" class="form-control form-control-sm" placeholder="Document Name" required></div>
                                <div class="col-12">
                                    <select name="document_type" class="form-select form-select-sm" required>
                                        <option value="" selected disabled>Select Type...</option>
                                        <option value="Resume/CV">Resume/CV</option>
                                        <option value="Application Form">Application Form</option>
                                        <option value="Contract">Contract</option>
                                        <option value="Job Offer">Job Offer</option>
                                        <option value="Certificate">Certificate</option>
                                        <option value="Memo">Memo</option>
                                        <option value="Evaluation">Evaluation</option>
                                        <option value="Medical">Medical Result</option>
                                        <option value="Clearance">Clearance</option>
                                        <option value="Resignation">Resignation Letter</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-12"><input type="file" name="document_file" class="form-control form-control-sm" required></div>
                                <div class="col-12"><button type="submit" class="btn btn-sm btn-secondary w-100">Upload Document</button></div>
                            </div>
                        </form>
                    </div>
                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- HR1 Fetch Modal -->
    <div class="modal fade" id="hr1FetchModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">HR1 Employee Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="hr1Table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Position</th>
                                    <th>Department</th>
                                    <th>Email</th>
                                    <th>Avatar</th>
                                </tr>
                            </thead>
                            <tbody id="hr1TableBody">
                                <!-- Data will be populated here -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="importHr1Btn" disabled>
                        <i class="fas fa-file-import me-1"></i> Import All
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Employee Modal -->
    <div class="modal fade" id="viewEmployeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Employee Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 text-center mb-3">
                            <div id="view_photo_container" class="mb-2"></div>
                            <h4 class="mt-2" id="view_name"></h4>
                            <p class="text-muted" id="view_id"></p>
                            <span class="badge" id="view_status"></span>
                        </div>
                        <div class="col-md-8">
                            <div class="p-3 border rounded">
                                <h6 class="text-primary fw-bold text-uppercase border-bottom pb-2 mb-3"><i class="fas fa-id-card me-2"></i>Personal Information</h6>
                                <div id="view_personal" class="mb-4"></div>

                                <h6 class="text-primary fw-bold text-uppercase border-bottom pb-2 mb-3"><i class="fas fa-briefcase me-2"></i>Employment Details</h6>
                                <div id="view_employment" class="mb-4"></div>

                                <h6 class="text-primary fw-bold text-uppercase border-bottom pb-2 mb-3"><i class="fas fa-money-bill-wave me-2"></i>Compensation & Benefits</h6>
                                <div id="view_compensation" class="mb-4"></div>

                                <h6 class="text-primary fw-bold text-uppercase border-bottom pb-2 mb-3"><i class="fas fa-chart-line me-2"></i>Performance & Records</h6>
                                <div id="view_performance" class="mb-4"></div>

                                <h6 class="text-primary fw-bold text-uppercase border-bottom pb-2 mb-3"><i class="fas fa-history me-2"></i>History & Qualifications</h6>
                                <div id="view_other" class="mb-4"></div>

                                <h6 class="text-primary fw-bold text-uppercase border-bottom pb-2 mb-3"><i class="fas fa-folder-open me-2"></i>201 Files</h6>
                                <div id="view_documents"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Google Gemini Audit Modal -->
    <div class="modal fade" id="geminiAuditModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-robot me-2"></i>Gemini AI System Audit</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="auditLoading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <h5 class="mt-3">Analyzing HR Data...</h5>
                        <p class="text-muted">Connecting to Google Gemini AI for insights.</p>
                    </div>
                    <div id="auditResults" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="runGeminiAudit()"><i class="fas fa-redo me-1"></i> Re-scan</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show alert message
        function showAlert(message, type = 'success') {
            const alertContainer = document.querySelector('.alert-container');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            alertContainer.appendChild(alert);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 5000);
        }
        
        // ... (Rest of existing scripts) ...

        // Fetch HR1 Data Handler
        const fetchBtn = document.getElementById('fetchHr1Btn');
        const importBtn = document.getElementById('importHr1Btn');
        let fetchedHR1Data = []; // Store fetched data

        if (fetchBtn) {
            fetchBtn.addEventListener('click', function() {
                const btn = this;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Fetching...';
                btn.disabled = true;
                
                // Disable import button while fetching
                if (importBtn) importBtn.disabled = true;

                fetch('http://localhost/HR1/api/employee_data.php')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        const tbody = document.getElementById('hr1TableBody');
                        tbody.innerHTML = '';
                        fetchedHR1Data = []; // Reset global data
                        
                        // Handle possible different response structures
                        const employees = Array.isArray(data) ? data : (data.data || []);
                        fetchedHR1Data = employees; // Store for import

                        if (employees.length > 0) {
                            // Enable import button
                            if (importBtn) importBtn.disabled = false;

                            employees.forEach(emp => {
                                const row = document.createElement('tr');
                                // Determine avatar - handle both full URLs and relative paths if needed
                                let avatarHtml = '<span class="text-muted small">No Img</span>';
                                if (emp.avatar_url) {
                                    avatarHtml = `<img src="${emp.avatar_url}" style="width: 30px; height: 30px; border-radius: 50%; object-fit: cover;">`;
                                }

                                row.innerHTML = `
                                    <td>${emp.id || '-'}</td>
                                    <td>${emp.first_name || ''} ${emp.last_name || ''}</td>
                                    <td>${emp.position || '-'}</td>
                                    <td>${emp.department || '-'}</td>
                                    <td>${emp.email || '-'}</td>
                                    <td>${avatarHtml}</td>
                                `;
                                tbody.appendChild(row);
                            });
                            
                            // Show modal
                            const modal = new bootstrap.Modal(document.getElementById('hr1FetchModal'));
                            modal.show();
                        } else {
                            showAlert('No data found from HR1 API', 'info');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching HR1 data:', error);
                        showAlert('Failed to fetch data from HR1. Please check the API Endpoint.', 'danger');
                    })
                    .finally(() => {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    });
            });
        }

        // Import Handler
        if (importBtn) {
            importBtn.addEventListener('click', function() {
                if (fetchedHR1Data.length === 0) {
                    showAlert('No data to import', 'warning');
                    return;
                }

                if (!confirm(`Are you sure you want to import ${fetchedHR1Data.length} employees? Duplicates (by email) will be skipped.`)) {
                    return;
                }

                const btn = this;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Importing...';
                btn.disabled = true;

                const formData = new FormData();
                formData.append('action', 'import_hr1_employees');
                formData.append('employees', JSON.stringify(fetchedHR1Data));

                fetch('', { // Post to self
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        // Close modal after delay and reload to show new employees
                        setTimeout(() => {
                            location.reload();
                        }, 1500);
                    } else {
                        showAlert(data.message || 'Import failed', 'danger');
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Import error:', error);
                    showAlert('An error occurred during import', 'danger');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
            });
        }


        // Photo preview
        function setupPhotoPreview() {
            const photoInput = document.getElementById('photoInput');
            if (photoInput) {
                photoInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            let preview = document.getElementById('photoPreview');
                            
                            // If we have a preview element (could be div or img)
                            if (preview) {
                                // If it's the placeholder div, we need to replace it with an img
                                if (preview.tagName !== 'IMG') {
                                    const img = document.createElement('img');
                                    img.src = e.target.result;
                                    img.className = 'employee-photo mb-2';
                                    img.alt = 'Photo Preview';
                                    img.id = 'photoPreview';
                                    
                                    // Replace the placeholder with the new image
                                    preview.parentNode.replaceChild(img, preview);
                                } else {
                                    // If it's already an image, just update the src
                                    preview.src = e.target.result;
                                }
                            }
                        }
                        reader.readAsDataURL(file);
                    }
                });
            }
        }

        // View Employee Handler
        function setupViewButtons() {
            document.querySelectorAll('.view-employee').forEach(btn => {
                btn.addEventListener('click', function() {
                    const id = this.dataset.id;
                    const formData = new FormData();
                    formData.append('action', 'get_employee_details');
                    formData.append('employee_id', id);

                    // Show loading or open modal
                    const modalElement = document.getElementById('viewEmployeeModal');
                    const modal = new bootstrap.Modal(modalElement);
                    modal.show();
                    
                    document.getElementById('view_name').innerText = 'Loading...';

                    fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            populateViewModal(data);
                        } else {
                            alert(data.message);
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Error fetching details');
                    });
                });
            });
        }

        function populateViewModal(data) {
            const emp = data.employee;
            const UPLOAD_DIR = 'uploads/'; 
            
            // Photo
            const photoContainer = document.getElementById('view_photo_container');
            if (emp.photo) {
                photoContainer.innerHTML = `<img src="${UPLOAD_DIR}${emp.photo}" class="employee-photo">`;
            } else {
                photoContainer.innerHTML = `<div class="photo-placeholder d-inline-flex align-items-center justify-content-center" style="width:120px;height:120px;border-radius:50%;border:3px solid var(--secondary);background-color:#e9ecef"><i class="fas fa-user fa-3x text-muted"></i></div>`;
            }

            document.getElementById('view_name').innerText = emp.name;
            document.getElementById('view_id').innerText = emp.employee_id || 'N/A';
            const statusBadge = document.getElementById('view_status');
            statusBadge.innerText = emp.status;
            statusBadge.className = `badge ${emp.status === 'Active' ? 'bg-success' : 'bg-secondary'}`;
            
            // --- PERSONAL INFORMATION ---
            let personalHtml = `
                <div class="row g-2 mb-3">
                    <div class="col-6"><small class="text-muted">Email</small><br>${emp.email || '-'}</div>
                    <div class="col-6"><small class="text-muted">Phone</small><br>${emp.phone || '-'}</div>
                    <div class="col-6"><small class="text-muted">Birth Date</small><br>${emp.birth_date || '-'}</div>
                    <div class="col-6"><small class="text-muted">Age</small><br>${emp.age || '-'}</div>
                    <div class="col-6"><small class="text-muted">Gender</small><br>${emp.gender || '-'}</div>
                    <div class="col-6"><small class="text-muted">Civil Status</small><br>${emp.civil_status || '-'}</div>
                    <div class="col-6"><small class="text-muted">Nationality</small><br>${emp.nationality || '-'}</div>
                    <div class="col-6"><small class="text-muted">Religion</small><br>${emp.religion || '-'}</div>
                    <div class="col-12"><small class="text-muted">Address</small><br>${emp.address || '-'}</div>
                </div>
            `;
            
            // Emergency Contacts
            if (data.contacts && data.contacts.length > 0) {
                personalHtml += '<h6 class="small fw-bold text-secondary mt-3">Emergency Contacts</h6><ul class="list-group list-group-flush small">';
                data.contacts.forEach(c => {
                    personalHtml += `<li class="list-group-item px-0 py-1">
                        <strong>${c.contact_name}</strong> (${c.relationship}) - ${c.phone}
                    </li>`;
                });
                personalHtml += '</ul>';
            }

            // Dependents
            if (data.dependents && data.dependents.length > 0) {
                personalHtml += '<h6 class="small fw-bold text-secondary mt-3">Dependents</h6><ul class="list-group list-group-flush small">';
                data.dependents.forEach(d => {
                    personalHtml += `<li class="list-group-item px-0 py-1">
                        <strong>${d.name}</strong> (${d.relationship}) - DOB: ${d.birth_date || 'N/A'}
                    </li>`;
                });
                personalHtml += '</ul>';
            }
            document.getElementById('view_personal').innerHTML = personalHtml;

            // --- EMPLOYMENT DETAILS ---
             const employmentHtml = `
                <div class="row g-2">
                    <div class="col-6"><small class="text-muted">Department</small><br>${emp.department || '-'}</div>
                    <div class="col-6"><small class="text-muted">Position</small><br>${emp.job_title || '-'}</div>
                    <div class="col-6"><small class="text-muted">Manager/Supervisor</small><br>${emp.manager || '-'}</div>
                    <div class="col-6"><small class="text-muted">Work Schedule</small><br>${emp.work_schedule || '-'}</div>
                    <div class="col-6"><small class="text-muted">Date Hired</small><br>${emp.date_hired || '-'}</div>
                    <div class="col-6"><small class="text-muted">Date Regularized</small><br>${emp.date_regularized || '-'}</div>
                    <div class="col-6"><small class="text-muted">Emp. Status</small><br>${emp.status || '-'}</div>
                    <div class="col-6"><small class="text-muted">Contract Type</small><br>${emp.contract || '-'}</div>
                    <div class="col-12 mt-2"><small class="text-muted">Job Description</small><p class="small mb-0 text-break">${emp.job_description || '-'}</p></div>
                </div>
            `;
            document.getElementById('view_employment').innerHTML = employmentHtml;

            // --- COMPENSATION & BENEFITS ---
            let compHtml = `
                 <div class="row g-2 mb-3">
                    <div class="col-6"><small class="text-muted">Basic Salary</small><br>${emp.salary ? parseFloat(emp.salary).toLocaleString('en-US', {style:'currency', currency:'PHP'}) : '-'}</div>
                    <div class="col-6"><small class="text-muted">Pay Grade</small><br>${emp.pay_grade || '-'}</div>
                    <div class="col-6"><small class="text-muted">Bank Name</small><br>${emp.bank_name || '-'}</div>
                    <div class="col-6"><small class="text-muted">Account No.</small><br>${emp.bank_account_no || '-'}</div>
                 </div>
                 <h6 class="small fw-bold text-secondary mt-3">Government IDs</h6>
                 <div class="row g-2 mb-3">
                    <div class="col-6"><small class="text-muted">SSS</small><br>${emp.sss_no || '-'}</div>
                    <div class="col-6"><small class="text-muted">TIN</small><br>${emp.tin_no || '-'}</div>
                    <div class="col-6"><small class="text-muted">PhilHealth</small><br>${emp.philhealth_no || '-'}</div>
                    <div class="col-6"><small class="text-muted">Pag-IBIG</small><br>${emp.pagibig_no || '-'}</div>
                 </div>
                 <h6 class="small fw-bold text-secondary mt-3">Benefits</h6>
                 <div class="row g-2 mb-3">
                    <div class="col-6"><small class="text-muted">HMO Provider</small><br>${emp.hmo_provider || '-'}</div>
                    <div class="col-6"><small class="text-muted">HMO Number</small><br>${emp.hmo_number || '-'}</div>
                    <div class="col-6"><small class="text-muted">VL Credits</small><br>${emp.leave_credits_vacation || '0'}</div>
                    <div class="col-6"><small class="text-muted">SL Credits</small><br>${emp.leave_credits_sick || '0'}</div>
                 </div>
            `;
            
            if (data.salary_history && data.salary_history.length > 0) {
                 compHtml += '<h6 class="small fw-bold text-secondary mt-3">Salary History</h6><table class="table table-sm table-bordered small"><thead><tr><th>Date</th><th>Amount</th><th>Type</th></tr></thead><tbody>';
                 data.salary_history.forEach(h => {
                     compHtml += `<tr>
                        <td>${h.effective_date}</td>
                        <td>${parseFloat(h.amount).toLocaleString('en-US', {style:'currency', currency:'PHP'})}</td>
                        <td>${h.type}</td>
                     </tr>`;
                 });
                 compHtml += '</tbody></table>';
            }
            document.getElementById('view_compensation').innerHTML = compHtml;

            // --- PERFORMANCE & RECORDS ---
            let perfHtml = '';
            if (data.performance && data.performance.length > 0) {
                perfHtml += '<h6 class="small fw-bold text-secondary">Performance Reviews</h6><div class="list-group list-group-flush small mb-3">';
                data.performance.forEach(p => {
                    perfHtml += `<div class="list-group-item px-0 py-2">
                        <div class="d-flex justify-content-between"><strong>Rating: ${p.rating}</strong> <span>${p.review_date}</span></div>
                        <div class="fst-italic text-muted">"${p.comments}"</div>
                    </div>`;
                });
                perfHtml += '</div>';
            } else { perfHtml += '<p class="small text-muted">No performance reviews recorded.</p>'; }

            if (data.disciplinary && data.disciplinary.length > 0) {
                perfHtml += '<h6 class="small fw-bold text-danger mt-3">Disciplinary Cases</h6><div class="list-group list-group-flush small">';
                data.disciplinary.forEach(c => {
                    perfHtml += `<div class="list-group-item px-0 py-2 border-start border-danger border-3 bg-light">
                        <div class="d-flex justify-content-between"><strong class="text-danger">${c.violation}</strong> <span>${c.date_reported}</span></div>
                        <div>Status: ${c.status} | Action: ${c.action_taken}</div>
                    </div>`;
                });
                perfHtml += '</div>';
            } else { perfHtml += '<p class="small text-muted">No disciplinary cases recorded.</p>'; }
            document.getElementById('view_performance').innerHTML = perfHtml;

             // --- HISTORY & QUALIFICATIONS ---
             let otherHtml = '<h6 class="small fw-bold text-secondary">Education</h6><ul class="list-group list-group-flush mb-3 small">';
             if(data.education && data.education.length > 0) {
                 data.education.forEach(edu => {
                     otherHtml += `<li class="list-group-item px-0 py-1">
                        <strong>${edu.school_name}</strong> - ${edu.level}<br>
                        <span class="text-muted">${edu.year_graduated || ''} ${edu.course ? '| ' + edu.course : ''}</span>
                     </li>`;
                 });
             } else { otherHtml += '<li class="list-group-item px-0 py-1 text-muted">No education records</li>'; }
             
             otherHtml += '</ul><h6 class="small fw-bold text-secondary mt-3">Work Experience</h6><ul class="list-group list-group-flush small">';
             if(data.work && data.work.length > 0) {
                 data.work.forEach(w => {
                     otherHtml += `<li class="list-group-item px-0 py-1">
                        <strong>${w.position}</strong> at ${w.company_name}<br>
                        <span class="text-muted">${w.date_from} - ${w.is_current ? 'Present' : w.date_to}</span>
                     </li>`;
                 });
             } else { otherHtml += '<li class="list-group-item px-0 py-1 text-muted">No work experience</li>'; }
             
             otherHtml += '</ul><h6 class="small fw-bold text-secondary mt-3">Seminars & Trainings</h6><ul class="list-group list-group-flush small">';
             if(data.seminars && data.seminars.length > 0) {
                 data.seminars.forEach(s => {
                     otherHtml += `<li class="list-group-item px-0 py-1"><strong>${s.seminar_name}</strong> <span class="text-muted">(${s.date_attended})</span></li>`;
                 });
             } else { otherHtml += '<li class="list-group-item px-0 py-1 text-muted">No seminars recorded</li>'; }

             otherHtml += '</ul><h6 class="small fw-bold text-secondary mt-3">Skills</h6><div class="d-flex flex-wrap gap-1">';
             if(data.skills && data.skills.length > 0) {
                 data.skills.forEach(s => {
                     otherHtml += `<span class="badge bg-secondary">${s.skill_name} (${s.proficiency_level})</span>`;
                 });
             } else { otherHtml += '<span class="small text-muted">No skills recorded</span>'; }
             otherHtml += '</div>';
             
             document.getElementById('view_other').innerHTML = otherHtml;
             
             // Documents (201 Files)
             let docsHtml = '<div class="list-group list-group-flush small">';
             if(data.documents && data.documents.length > 0) {
                 data.documents.forEach(doc => {
                     docsHtml += `<a href="${UPLOAD_DIR}${doc.file_path}" target="_blank" class="list-group-item list-group-item-action px-0">
                        <div class="d-flex w-100 justify-content-between">
                          <h6 class="mb-1">${doc.document_name}</h6>
                          <small>${doc.document_type}</small>
                        </div>
                        <small class="text-muted">Uploaded: ${doc.upload_date}</small>
                     </a>`;
                 });
             } else { docsHtml += '<div class="list-group-item px-0 text-muted">No documents found</div>'; }
             docsHtml += '</div>';
             
             document.getElementById('view_documents').innerHTML = docsHtml;
        }

        // Delete confirmation functions
        function setupDeleteHandler(selector, type) {
            document.querySelectorAll(selector).forEach(button => {
                button.addEventListener('click', function() {
                    if (confirm('Are you sure you want to delete this ' + type + '?')) {
                        const formData = new FormData();
                        formData.append('action', 'delete_' + type);
                        formData.append(type + '_id', this.dataset[type + 'Id']);
                        formData.append('employee_id', this.dataset.employeeId);
                        formData.append('ajax', 'true');

                        // Show loading
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        this.disabled = true;

                        fetch('', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    // Remove the card element
                                    const cardElement = this.closest('.card');
                                    if (cardElement) {
                                        cardElement.remove();
                                    }
                                    showAlert(data.message, 'success');
                                } else {
                                    showAlert(data.message, 'danger');
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showAlert('Error deleting record: ' + error.message, 'danger');
                            })
                            .finally(() => {
                                // Restore button state
                                this.innerHTML = originalHTML;
                                this.disabled = false;
                            });
                    }
                });
            });
        }

        // Setup delete handlers for all types
        setupDeleteHandler('.delete-contact', 'contact');
        setupDeleteHandler('.delete-education', 'education');
        setupDeleteHandler('.delete-seminar', 'seminar');
        setupDeleteHandler('.delete-skill', 'skill');
        setupDeleteHandler('.delete-work', 'work_experience');
        setupDeleteHandler('.delete-dependent', 'dependent');
        setupDeleteHandler('.delete-salary', 'salary');
        setupDeleteHandler('.delete-performance', 'performance');
        setupDeleteHandler('.delete-disciplinary', 'disciplinary');
        setupDeleteHandler('.delete-document', 'document');

        // Form submission with AJAX
        function setupFormHandler(formId) {
            const form = document.getElementById(formId);
            if (!form) return;

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('ajax', 'true');

                // Show loading state
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                submitBtn.disabled = true;

                fetch('', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showAlert(data.message, 'success');
                            // Reset form for add forms (not profile/update forms)
                            if (formId !== 'profileForm' && formId !== 'employmentForm' && formId !== 'compForm') {
                                this.reset();
                                // Reset hidden ID fields
                                const idFields = this.querySelectorAll('input[type="hidden"][id$="_id"]');
                                idFields.forEach(field => field.value = '');
                            }
                            // Reload page after success to show updated data
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        } else {
                            showAlert(data.message, 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showAlert('Error saving data: ' + error.message, 'danger');
                    })
                    .finally(() => {
                        // Restore button state
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    });
            });
        }

        // Setup form handlers for all forms
        setupFormHandler('profileForm');
        setupFormHandler('contactForm');
        setupFormHandler('educationForm');
        setupFormHandler('seminarForm');
        setupFormHandler('skillForm');
        setupFormHandler('workForm');
        setupFormHandler('documentForm');
        setupFormHandler('dependentForm');
        setupFormHandler('employmentForm');
        setupFormHandler('compForm');
        setupFormHandler('salaryForm');
        setupFormHandler('performanceForm');
        setupFormHandler('disciplinaryForm');

        // Handle current job checkbox
        document.getElementById('is_current')?.addEventListener('change', function() {
            const dateToField = document.querySelector('input[name="date_to"]');
            if (this.checked) {
                dateToField.disabled = true;
                dateToField.value = '';
            } else {
                dateToField.disabled = false;
            }
        });

        // Clear form when adding new items
        document.querySelectorAll('form[id$="Form"]').forEach(form => {
            if (form.id !== 'profileForm' && form.id !== 'employmentForm' && form.id !== 'compForm') {
                const clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'btn btn-outline-secondary btn-sm mt-2';
                clearBtn.innerHTML = '<i class="fas fa-times"></i> Clear Form';
                clearBtn.addEventListener('click', function() {
                    form.reset();
                    // Reset hidden ID fields
                    const idFields = form.querySelectorAll('input[type="hidden"][id$="_id"]');
                    idFields.forEach(field => field.value = '');
                });
                form.appendChild(clearBtn);
            }
        });

        // Initialize all handlers on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-open Edit Modal if present
            const editModalEl = document.getElementById('editEmployeeModal');
            if (editModalEl) {
                const editModal = new bootstrap.Modal(editModalEl);
                editModal.show();
                
                // Redirect on close to clear 'edit' param
                editModalEl.addEventListener('hidden.bs.modal', function () {
                    window.location.href = window.location.pathname;
                });
            }

            setupPhotoPreview();
            setupViewButtons();
            
            setupDeleteHandler('.delete-contact', 'contact');
            setupDeleteHandler('.delete-education', 'education');
            setupDeleteHandler('.delete-seminar', 'seminar');
            setupDeleteHandler('.delete-skill', 'skill');
            setupDeleteHandler('.delete-work', 'work_experience');
            setupDeleteHandler('.delete-dependent', 'dependent');
            setupDeleteHandler('.delete-salary', 'salary');
            setupDeleteHandler('.delete-performance', 'performance');
            setupDeleteHandler('.delete-disciplinary', 'disciplinary');
            setupDeleteHandler('.delete-document', 'document');
        });

        // Gemini Audit Function
        function runGeminiAudit() {
            // Show Modal
            const modalElement = document.getElementById('geminiAuditModal');
            if(modalElement) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
                
                const loading = document.getElementById('auditLoading');
                const results = document.getElementById('auditResults');
                
                if(loading) loading.style.display = 'block';
                if(results) results.style.display = 'none';

                // Fetch from Local API
                fetch('http://localhost/HR4/api/gemini_audit.php')
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if(loading) loading.style.display = 'none';
                        if(results) {
                            results.style.display = 'block';
                            
                            let html = '';
                            if (data.success) {
                                html += `<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Audit Completed</div>`;
                                if(data.analysis) {
                                     // Format the analysis cleanly
                                     const analysisText = typeof data.analysis === 'string' ? data.analysis : JSON.stringify(data.analysis, null, 2);
                                     html += `<div class="p-3 bg-light border rounded"><pre style="white-space: pre-wrap; font-family: inherit; margin:0;">${analysisText}</pre></div>`;
                                } else {
                                     html += `<div class="alert alert-info">No analysis data returned.</div>`;
                                }
                            } else {
                                html += `<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>${data.message || 'Audit warning'}</div>`;
                            }
                            results.innerHTML = html;
                        }
                    })
                    .catch(error => {
                        console.error('Gemini Audit Error:', error);
                        if(loading) loading.style.display = 'none';
                        if(results) {
                            results.style.display = 'block';
                            results.innerHTML = `<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Error: ${error.message}</div>`;
                        }
                    });
            } else {
                alert('Audit Modal not defined in page.');
            }
        }
    </script>
</body>

</html>