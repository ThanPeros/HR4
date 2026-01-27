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
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `date_hired` DATE NULL AFTER `passport_no`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `date_regularized` DATE NULL AFTER `date_hired`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `resume_file` VARCHAR(255) NULL AFTER `photo`",
            "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `diploma_file` VARCHAR(255) NULL AFTER `resume_file`"
        ];

        foreach ($alter_queries as $query) {
            try {
                $pdo->exec($query);
            } catch (Exception $e) {
                // Column might already exist, continue
                error_log("Alter table warning: " . $e->getMessage());
            }
        }

        // Create emergency_contacts table if it doesn't exist
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
    if ($type === 'resume' || $type === 'diploma' || $type === 'certificate' || $type === 'education_diploma' || $type === 'certification') {
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

// Get all employees
function getEmployees($department = null, $status = null)
{
    $pdo = getDB();
    $sql = "SELECT * FROM `employees` WHERE 1=1";
    $params = [];

    if ($department) {
        $sql .= " AND department = ?";
        $params[] = $department;
    }

    if ($status) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

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
        'date_regularized'
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
                            'work' => getWorkExperience($employee_id)
                        ];
                    } else {
                        $response['message'] = 'Employee not found';
                    }
                } else {
                    $response['message'] = 'Invalid ID';
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

// Get employees for listing
$department_filter = $_GET['department'] ?? null;
$status_filter = $_GET['status'] ?? null;
$employees = getEmployees($department_filter, $status_filter);

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

        <div class="row">
            <!-- Employee List -->
            <div class="col-lg-8">
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
                            <form method="GET" class="row g-2">
                                <div class="col-md-4">
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
                                <div class="col-md-4">
                                    <select name="status" class="form-select" onchange="this.form.submit()">
                                        <option value="">All Statuses</option>
                                        <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-outline-secondary">Clear Filters</a>
                                </div>
                            </form>
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

            <!-- Edit Profile Sidebar -->
            <div class="col-lg-4">
                <?php if ($edit_employee): ?>
                    <!-- Profile Edit Form -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Edit Profile</span>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-sm btn-outline-secondary">Close</a>
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data" id="profileForm">
                                <input type="hidden" name="action" value="update_profile">
                                <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">

                                <!-- Photo Upload -->
                                <div class="text-center mb-3">
                                    <?php if (!empty($edit_employee['photo']) && file_exists(UPLOAD_DIR . $edit_employee['photo'])): ?>
                                        <img src="<?php echo UPLOAD_DIR . $edit_employee['photo']; ?>" class="employee-photo mb-2" style="width:120px;height:120px;" alt="Photo" id="photoPreview">
                                    <?php else: ?>
                                        <div class="photo-placeholder mb-2 d-inline-flex align-items-center justify-content-center" style="width:120px;height:120px;" id="photoPreview">
                                            <i class="fas fa-user text-muted fa-2x"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <input type="file" name="photo" id="photoInput" accept="image/*" class="form-control form-control-sm">
                                        <small class="text-muted">Max 2MB (JPEG, PNG, GIF)</small>
                                    </div>
                                </div>

                                <!-- Document Uploads -->
                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Resume</label>
                                        <?php if (!empty($edit_employee['resume_file'])): ?>
                                            <div class="d-flex align-items-center">
                                                <a href="<?php echo UPLOAD_DIR . $edit_employee['resume_file']; ?>" target="_blank" class="btn btn-sm btn-outline-success file-download-btn me-2">
                                                    <i class="fas fa-download"></i> View
                                                </a>
                                                <small><?php echo $edit_employee['resume_file']; ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" name="resume_file" class="form-control form-control-sm mt-1" accept=".pdf,.doc,.docx">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Diploma</label>
                                        <?php if (!empty($edit_employee['diploma_file'])): ?>
                                            <div class="d-flex align-items-center">
                                                <a href="<?php echo UPLOAD_DIR . $edit_employee['diploma_file']; ?>" target="_blank" class="btn btn-sm btn-outline-success file-download-btn me-2">
                                                    <i class="fas fa-download"></i> View
                                                </a>
                                                <small><?php echo $edit_employee['diploma_file']; ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <input type="file" name="diploma_file" class="form-control form-control-sm mt-1" accept=".pdf,.doc,.docx,image/*">
                                    </div>
                                </div>

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Employee ID</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_employee['employee_id'] ?? 'EMP' . str_pad($edit_employee['id'], 4, '0', STR_PAD_LEFT)); ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Name *</label>
                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($edit_employee['name']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($edit_employee['email'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Phone</label>
                                        <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($edit_employee['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" class="form-control" rows="2"><?php echo htmlspecialchars($edit_employee['address'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Personal Information -->
                                    <div class="col-md-6">
                                        <label class="form-label">Birth Date</label>
                                        <input type="date" name="birth_date" class="form-control" value="<?php echo htmlspecialchars($edit_employee['birth_date'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Age</label>
                                        <input type="number" name="age" class="form-control" value="<?php echo htmlspecialchars($edit_employee['age'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Birth Place</label>
                                        <input type="text" name="birth_place" class="form-control" value="<?php echo htmlspecialchars($edit_employee['birth_place'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Civil Status</label>
                                        <select name="civil_status" class="form-select">
                                            <option value="">Select</option>
                                            <option value="Single" <?php echo ($edit_employee['civil_status'] ?? '') === 'Single' ? 'selected' : ''; ?>>Single</option>
                                            <option value="Married" <?php echo ($edit_employee['civil_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                                            <option value="Divorced" <?php echo ($edit_employee['civil_status'] ?? '') === 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                            <option value="Widowed" <?php echo ($edit_employee['civil_status'] ?? '') === 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Nationality</label>
                                        <input type="text" name="nationality" class="form-control" value="<?php echo htmlspecialchars($edit_employee['nationality'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Religion</label>
                                        <input type="text" name="religion" class="form-control" value="<?php echo htmlspecialchars($edit_employee['religion'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Gender</label>
                                        <select name="gender" class="form-select">
                                            <option value="Male" <?php echo ($edit_employee['gender'] ?? '') === 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo ($edit_employee['gender'] ?? '') === 'Female' ? 'selected' : ''; ?>>Female</option>
                                        </select>
                                    </div>

                                    <!-- Government IDs -->
                                    <div class="col-md-6">
                                        <label class="form-label">SSS No.</label>
                                        <input type="text" name="sss_no" class="form-control" value="<?php echo htmlspecialchars($edit_employee['sss_no'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">TIN No.</label>
                                        <input type="text" name="tin_no" class="form-control" value="<?php echo htmlspecialchars($edit_employee['tin_no'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">PhilHealth No.</label>
                                        <input type="text" name="philhealth_no" class="form-control" value="<?php echo htmlspecialchars($edit_employee['philhealth_no'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Pag-IBIG No.</label>
                                        <input type="text" name="pagibig_no" class="form-control" value="<?php echo htmlspecialchars($edit_employee['pagibig_no'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Passport No.</label>
                                        <input type="text" name="passport_no" class="form-control" value="<?php echo htmlspecialchars($edit_employee['passport_no'] ?? ''); ?>">
                                    </div>

                                    <!-- Employment Details -->
                                    <div class="col-md-6">
                                        <label class="form-label">Department</label>
                                        <select name="department" class="form-select">
                                            <?php
                                            foreach ($depts as $dept) {
                                                $selected = $edit_employee['department'] === $dept ? 'selected' : '';
                                                echo "<option value=\"$dept\" $selected>$dept</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Job Title</label>
                                        <input type="text" name="job_title" class="form-control" value="<?php echo htmlspecialchars($edit_employee['job_title']); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Contract Type</label>
                                        <select name="contract" class="form-select">
                                            <option value="Regular" <?php echo ($edit_employee['contract'] ?? '') === 'Regular' ? 'selected' : ''; ?>>Regular</option>
                                            <option value="Probationary" <?php echo ($edit_employee['contract'] ?? '') === 'Probationary' ? 'selected' : ''; ?>>Probationary</option>
                                            <option value="Contract" <?php echo ($edit_employee['contract'] ?? '') === 'Contract' ? 'selected' : ''; ?>>Contract</option>
                                            <option value="Project-Based" <?php echo ($edit_employee['contract'] ?? '') === 'Project-Based' ? 'selected' : ''; ?>>Project-Based</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Salary</label>
                                        <input type="number" name="salary" class="form-control" value="<?php echo htmlspecialchars($edit_employee['salary'] ?? ''); ?>" step="0.01">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="Active" <?php echo $edit_employee['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="Inactive" <?php echo $edit_employee['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date Hired</label>
                                        <input type="date" name="date_hired" class="form-control" value="<?php echo htmlspecialchars($edit_employee['date_hired'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date Regularized</label>
                                        <input type="date" name="date_regularized" class="form-control" value="<?php echo htmlspecialchars($edit_employee['date_regularized'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-save me-1"></i> Update Profile
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Educational Background -->
                    <div class="card mt-3">
                        <div class="card-header">
                            Educational Background
                        </div>
                        <div class="card-body">
                            <?php foreach ($educational_background as $education): ?>
                                <div class="card mb-2">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($education['level']); ?></strong>
                                                <br>
                                                <small><?php echo htmlspecialchars($education['school_name']); ?></small>
                                                <?php if (!empty($education['course'])): ?>
                                                    <br><small>Course: <?php echo htmlspecialchars($education['course']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($education['year_graduated'])): ?>
                                                    <br><small>Year: <?php echo htmlspecialchars($education['year_graduated']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($education['honors'])): ?>
                                                    <br><small>Honors: <?php echo htmlspecialchars($education['honors']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($education['diploma_file'])): ?>
                                                    <br>
                                                    <a href="<?php echo UPLOAD_DIR . $education['diploma_file']; ?>" target="_blank" class="btn btn-sm btn-outline-success file-download-btn mt-1">
                                                        <i class="fas fa-download"></i> Diploma
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-danger delete-education"
                                                    data-education-id="<?php echo $education['id']; ?>"
                                                    data-employee-id="<?php echo $edit_employee['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Add Education Form -->
                            <form method="POST" enctype="multipart/form-data" id="educationForm" class="mt-3">
                                <input type="hidden" name="action" value="save_education">
                                <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                                <input type="hidden" name="education_id" id="education_id" value="">

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Level *</label>
                                        <select name="level" class="form-select" required>
                                            <option value="">Select Level</option>
                                            <option value="Elementary">Elementary</option>
                                            <option value="High School">High School</option>
                                            <option value="College">College</option>
                                            <option value="Bachelor">Bachelor</option>
                                            <option value="Master">Master</option>
                                            <option value="Doctorate">Doctorate</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">School Name *</label>
                                        <input type="text" name="school_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Course/Major</label>
                                        <input type="text" name="course" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Year Graduated</label>
                                        <input type="number" name="year_graduated" class="form-control" min="1900" max="2030">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Honors/Awards</label>
                                        <input type="text" name="honors" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Diploma File</label>
                                        <input type="file" name="diploma_file" class="form-control" accept=".pdf,.doc,.docx,image/*">
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-plus me-1"></i> Add Education
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Work Experience -->
                    <div class="card mt-3">
                        <div class="card-header">
                            Work Experience
                        </div>
                        <div class="card-body">
                            <?php foreach ($work_experience as $work): ?>
                                <div class="card mb-2">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($work['position']); ?></strong>
                                                <br>
                                                <small><?php echo htmlspecialchars($work['company_name']); ?></small>
                                                <br>
                                                <small>
                                                    <?php echo htmlspecialchars($work['date_from']); ?> -
                                                    <?php echo $work['is_current'] ? 'Present' : htmlspecialchars($work['date_to']); ?>
                                                    <?php if (!empty($work['years_experience'])): ?>
                                                        <br><small>Years: <?php echo htmlspecialchars($work['years_experience']); ?> years</small>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-danger delete-work"
                                                    data-work-experience-id="<?php echo $work['id']; ?>"
                                                    data-employee-id="<?php echo $edit_employee['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Add Work Experience Form -->
                            <form method="POST" id="workForm" class="mt-3">
                                <input type="hidden" name="action" value="save_work_experience">
                                <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                                <input type="hidden" name="work_id" id="work_id" value="">

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Company Name *</label>
                                        <input type="text" name="company_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Position *</label>
                                        <input type="text" name="position" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date From *</label>
                                        <input type="date" name="date_from" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date To</label>
                                        <input type="date" name="date_to" class="form-control">
                                        <div class="form-check mt-1">
                                            <input type="checkbox" name="is_current" value="1" class="form-check-input" id="is_current">
                                            <label class="form-check-label" for="is_current">Currently working here</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Years of Experience</label>
                                        <input type="number" name="years_experience" class="form-control" min="0" max="50">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Reference Name</label>
                                        <input type="text" name="reference_name" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Reference Contact</label>
                                        <input type="text" name="reference_contact" class="form-control">
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-plus me-1"></i> Add Work Experience
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Skills -->
                    <div class="card mt-3">
                        <div class="card-header">
                            Skills
                        </div>
                        <div class="card-body">
                            <?php foreach ($skills as $skill): ?>
                                <div class="card mb-2">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($skill['skill_name']); ?></strong>
                                                <br>
                                                <small>Proficiency: <?php echo htmlspecialchars($skill['proficiency_level']); ?></small>
                                                <?php if (!empty($skill['certification'])): ?>
                                                    <br>
                                                    <a href="<?php echo UPLOAD_DIR . $skill['certification']; ?>" target="_blank" class="btn btn-sm btn-outline-success file-download-btn mt-1">
                                                        <i class="fas fa-download"></i> Certificate
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-danger delete-skill"
                                                    data-skill-id="<?php echo $skill['id']; ?>"
                                                    data-employee-id="<?php echo $edit_employee['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Add Skill Form -->
                            <form method="POST" enctype="multipart/form-data" id="skillForm" class="mt-3">
                                <input type="hidden" name="action" value="save_skill">
                                <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                                <input type="hidden" name="skill_id" id="skill_id" value="">

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Skill Name *</label>
                                        <input type="text" name="skill_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Proficiency Level</label>
                                        <select name="proficiency_level" class="form-select">
                                            <option value="Beginner">Beginner</option>
                                            <option value="Intermediate">Intermediate</option>
                                            <option value="Advanced">Advanced</option>
                                            <option value="Expert">Expert</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Certification File</label>
                                        <input type="file" name="certification" class="form-control" accept=".pdf,.doc,.docx,image/*">
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-plus me-1"></i> Add Skill
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Seminars & Trainings -->
                    <div class="card mt-3">
                        <div class="card-header">
                            Seminars & Trainings
                        </div>
                        <div class="card-body">
                            <?php foreach ($seminars as $seminar): ?>
                                <div class="card mb-2">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($seminar['seminar_name']); ?></strong>
                                                <br>
                                                <small>Organizer: <?php echo htmlspecialchars($seminar['organizer']); ?></small>
                                                <br>
                                                <small>
                                                    <?php echo htmlspecialchars($seminar['date_from']); ?> -
                                                    <?php echo htmlspecialchars($seminar['date_to']); ?>
                                                    <?php if (!empty($seminar['hours'])): ?>
                                                        (<?php echo htmlspecialchars($seminar['hours']); ?> hours)
                                                    <?php endif; ?>
                                                </small>
                                                <?php if (!empty($seminar['certificate'])): ?>
                                                    <br>
                                                    <a href="<?php echo UPLOAD_DIR . $seminar['certificate']; ?>" target="_blank" class="btn btn-sm btn-outline-success file-download-btn mt-1">
                                                        <i class="fas fa-download"></i> Certificate
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-danger delete-seminar"
                                                    data-seminar-id="<?php echo $seminar['id']; ?>"
                                                    data-employee-id="<?php echo $edit_employee['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Add Seminar Form -->
                            <form method="POST" enctype="multipart/form-data" id="seminarForm" class="mt-3">
                                <input type="hidden" name="action" value="save_seminar">
                                <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                                <input type="hidden" name="seminar_id" id="seminar_id" value="">

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Seminar Name *</label>
                                        <input type="text" name="seminar_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Organizer *</label>
                                        <input type="text" name="organizer" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date From *</label>
                                        <input type="date" name="date_from" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date To *</label>
                                        <input type="date" name="date_to" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Hours</label>
                                        <input type="number" name="hours" class="form-control" min="1">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Certificate</label>
                                        <input type="file" name="certificate" class="form-control" accept=".pdf,.doc,.docx,image/*">
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-plus me-1"></i> Add Seminar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Emergency Contacts -->
                    <div class="card mt-3">
                        <div class="card-header">
                            Emergency Contacts
                        </div>
                        <div class="card-body">
                            <?php foreach ($emergency_contacts as $contact): ?>
                                <div class="card emergency-contact-card <?php echo $contact['is_primary'] ? 'primary-contact' : ''; ?> mb-2">
                                    <div class="card-body py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($contact['contact_name']); ?></strong>
                                                <small class="text-muted">(<?php echo htmlspecialchars($contact['relationship']); ?>)</small>
                                                <?php if ($contact['is_primary']): ?>
                                                    <span class="badge bg-success ms-1">Primary</span>
                                                <?php endif; ?>
                                                <br>
                                                <small><?php echo htmlspecialchars($contact['phone']); ?></small>
                                            </div>
                                            <div>
                                                <button class="btn btn-sm btn-outline-danger delete-contact"
                                                    data-contact-id="<?php echo $contact['id']; ?>"
                                                    data-employee-id="<?php echo $edit_employee['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Add Contact Form -->
                            <form method="POST" id="contactForm" class="mt-3">
                                <input type="hidden" name="action" value="save_contact">
                                <input type="hidden" name="employee_id" value="<?php echo $edit_employee['id']; ?>">
                                <input type="hidden" name="contact_id" id="contact_id" value="">

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label">Contact Name *</label>
                                        <input type="text" name="contact_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Relationship *</label>
                                        <input type="text" name="relationship" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Phone *</label>
                                        <input type="text" name="phone" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Address</label>
                                        <textarea name="address" class="form-control" rows="2"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input type="checkbox" name="is_primary" value="1" class="form-check-input" id="is_primary_contact">
                                            <label class="form-check-label" for="is_primary_contact">Set as primary contact</label>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-plus me-1"></i> Add Contact
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Welcome Card -->
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <h5>Employee Profiles</h5>
                            <p class="text-muted">Select an employee from the list to view and edit their profile information.</p>
                        </div>
                    </div>
                <?php endif; ?>
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
                            <ul class="nav nav-tabs" id="viewTabs" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_personal" type="button">Personal</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_employment" type="button">Employment</button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_other" type="button">Background</button>
                                </li>
                            </ul>
                            <div class="tab-content p-3 border border-top-0 rounded-bottom">
                                <div class="tab-pane fade show active" id="tab_personal"></div>
                                <div class="tab-pane fade" id="tab_employment"></div>
                                <div class="tab-pane fade" id="tab_other"></div>
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
            
            // Personal
            const personalHtml = `
                <div class="row g-2">
                    <div class="col-6"><small class="text-muted">Email</small><br>${emp.email || '-'}</div>
                    <div class="col-6"><small class="text-muted">Phone</small><br>${emp.phone || '-'}</div>
                    <div class="col-6"><small class="text-muted">Birth Date</small><br>${emp.birth_date || '-'}</div>
                    <div class="col-6"><small class="text-muted">Age</small><br>${emp.age || '-'}</div>
                    <div class="col-6"><small class="text-muted">Gender</small><br>${emp.gender || '-'}</div>
                    <div class="col-6"><small class="text-muted">Civil Status</small><br>${emp.civil_status || '-'}</div>
                    <div class="col-12"><small class="text-muted">Address</small><br>${emp.address || '-'}</div>
                </div>
            `;
            document.getElementById('tab_personal').innerHTML = personalHtml;
            
            // Employment
             const employmentHtml = `
                <div class="row g-2">
                    <div class="col-6"><small class="text-muted">Department</small><br>${emp.department || '-'}</div>
                    <div class="col-6"><small class="text-muted">Position</small><br>${emp.job_title || '-'}</div>
                    <div class="col-6"><small class="text-muted">Date Hired</small><br>${emp.date_hired || '-'}</div>
                    <div class="col-6"><small class="text-muted">Emp. Status</small><br>${emp.employment_status || '-'}</div>
                    <div class="col-6"><small class="text-muted">Salary</small><br>${emp.salary || '-'}</div>
                    <div class="col-6"><small class="text-muted">SSS</small><br>${emp.sss_no || '-'}</div>
                    <div class="col-6"><small class="text-muted">TIN</small><br>${emp.tin_no || '-'}</div>
                    <div class="col-6"><small class="text-muted">PhilHealth</small><br>${emp.philhealth_no || '-'}</div>
                </div>
            `;
            document.getElementById('tab_employment').innerHTML = employmentHtml;

             // Background
             let otherHtml = '<h6 class="mb-2">Education</h6><ul class="list-group list-group-flush mb-3 small">';
             if(data.education && data.education.length > 0) {
                 data.education.forEach(edu => {
                     otherHtml += `<li class="list-group-item px-0">
                        <strong>${edu.school_name}</strong> - ${edu.level}<br>
                        <span class="text-muted">${edu.year_graduated || ''} ${edu.course ? '| ' + edu.course : ''}</span>
                     </li>`;
                 });
             } else { otherHtml += '<li class="list-group-item px-0 text-muted">No education records</li>'; }
             
             otherHtml += '</ul><h6 class="mb-2">Work Experience</h6><ul class="list-group list-group-flush small">';
             if(data.work && data.work.length > 0) {
                 data.work.forEach(w => {
                     otherHtml += `<li class="list-group-item px-0">
                        <strong>${w.position}</strong> at ${w.company_name}<br>
                        <span class="text-muted">${w.date_from} - ${w.is_current ? 'Present' : w.date_to}</span>
                     </li>`;
                 });
             } else { otherHtml += '<li class="list-group-item px-0 text-muted">No work experience</li>'; }
             otherHtml += '</ul>';
             
             document.getElementById('tab_other').innerHTML = otherHtml;
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
                            // Reset form for add forms (not profile form)
                            if (formId !== 'profileForm') {
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
            if (form.id !== 'profileForm') {
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
            setupPhotoPreview();
            setupViewButtons();
            
            setupDeleteHandler('.delete-contact', 'contact');
            setupDeleteHandler('.delete-education', 'education');
            setupDeleteHandler('.delete-seminar', 'seminar');
            setupDeleteHandler('.delete-skill', 'skill');
            setupDeleteHandler('.delete-work', 'work_experience');
        });
    </script>
</body>

</html>