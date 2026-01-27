<?php
// includes/functions.php - Shared functions for compensation planning

// Database table creation functions
function createCompensationTables($pdo)
{
    $tables = [
        "salary_grades" => "CREATE TABLE IF NOT EXISTS salary_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grade_level VARCHAR(10) NOT NULL UNIQUE,
            grade_name VARCHAR(100) NOT NULL,
            min_salary DECIMAL(12,2) NOT NULL,
            mid_salary DECIMAL(12,2) NOT NULL,
            max_salary DECIMAL(12,2) NOT NULL,
            step_count INT DEFAULT 5,
            description TEXT,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        "allowance_matrix" => "CREATE TABLE IF NOT EXISTS allowance_matrix (
            id INT AUTO_INCREMENT PRIMARY KEY,
            allowance_type ENUM('Transportation','Meal','Communication','Housing','Position','Other') NOT NULL,
            allowance_name VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            amount_type ENUM('Fixed','Percentage') DEFAULT 'Fixed',
            eligibility_criteria TEXT,
            department VARCHAR(50),
            employment_type ENUM('Regular','Contract','Probationary','All') DEFAULT 'All',
            status ENUM('Active','Inactive') DEFAULT 'Active',
            effective_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        "compensation_rules" => "CREATE TABLE IF NOT EXISTS compensation_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(200) NOT NULL,
            rule_type ENUM('Employment Type','Position Level','Department','Shift','Other') NOT NULL,
            description TEXT NOT NULL,
            eligibility_criteria TEXT NOT NULL,
            application_rules TEXT NOT NULL,
            effective_date DATE NOT NULL,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        "salary_movements" => "CREATE TABLE IF NOT EXISTS salary_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            movement_type ENUM('Annual Review','Merit Increase','Promotion','COLA','Step Movement','Other') NOT NULL,
            employee_id INT,
            employee_name VARCHAR(100),
            department VARCHAR(50),
            previous_salary DECIMAL(12,2),
            new_salary DECIMAL(12,2),
            increase_amount DECIMAL(12,2),
            increase_percentage DECIMAL(5,2),
            effective_date DATE NOT NULL,
            reason TEXT,
            approved_by VARCHAR(100),
            status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        "bonus_structures" => "CREATE TABLE IF NOT EXISTS bonus_structures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bonus_type ENUM('Performance','Attendance','Productivity','13th Month','Special','Other') NOT NULL,
            bonus_name VARCHAR(200) NOT NULL,
            calculation_method ENUM('Fixed Amount','Percentage','Tiered','Formula') DEFAULT 'Fixed Amount',
            eligibility_criteria TEXT NOT NULL,
            amount DECIMAL(12,2),
            percentage DECIMAL(5,2),
            formula TEXT,
            payment_schedule VARCHAR(100),
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    ];

    foreach ($tables as $tableName => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Table might already exist
            error_log("Table creation error for $tableName: " . $e->getMessage());
        }
    }
}

function insertSampleData($pdo)
{
    // Insert sample salary grades
    $salaryGrades = [
        ['SG-1', 'Entry Level', 15000.00, 18000.00, 22000.00, 5, 'Fresh graduates and entry-level positions with 0-2 years experience'],
        ['SG-2', 'Junior Associate', 18000.00, 22000.00, 27000.00, 5, 'Junior roles with 2-3 years of relevant experience'],
        ['SG-3', 'Associate', 22000.00, 28000.00, 35000.00, 5, 'Mid-level professionals with 3-5 years experience'],
        ['SG-4', 'Senior Associate', 28000.00, 35000.00, 45000.00, 5, 'Experienced professionals with 5-7 years in specialized roles'],
        ['SG-5', 'Team Lead', 35000.00, 45000.00, 60000.00, 5, 'Team leadership roles with supervisory responsibilities'],
        ['SG-6', 'Manager', 45000.00, 60000.00, 80000.00, 5, 'Management positions with department oversight'],
        ['SG-7', 'Senior Manager', 60000.00, 80000.00, 100000.00, 5, 'Senior management with multiple team oversight'],
        ['SG-8', 'Director', 80000.00, 100000.00, 130000.00, 5, 'Executive leadership with strategic planning responsibilities']
    ];

    foreach ($salaryGrades as $grade) {
        $checkSql = "SELECT COUNT(*) FROM salary_grades WHERE grade_level = ?";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$grade[0]]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $sql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($grade);
        }
    }

    // Insert sample allowances
    $allowances = [
        ['Transportation', 'Monthly Transport Allowance', 2000.00, 'Fixed', 'All regular employees', 'All', 'Regular', '2024-01-01'],
        ['Meal', 'Daily Meal Allowance', 150.00, 'Fixed', 'Employees working onsite', 'All', 'All', '2024-01-01'],
        ['Communication', 'Monthly Communication', 1000.00, 'Fixed', 'Employees requiring regular client communication', 'All', 'Regular', '2024-01-01'],
        ['Position', 'Manager Position Allowance', 5000.00, 'Fixed', 'All managerial positions', 'All', 'Regular', '2024-01-01'],
        ['Housing', 'Housing Allowance', 8000.00, 'Fixed', 'Relocated employees and senior managers', 'All', 'Regular', '2024-01-01'],
        ['Transportation', 'Project Transport', 3000.00, 'Fixed', 'Employees assigned to offsite projects', 'IT', 'Contract', '2024-01-01']
    ];

    foreach ($allowances as $allowance) {
        $checkSql = "SELECT COUNT(*) FROM allowance_matrix WHERE allowance_name = ?";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$allowance[1]]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $sql = "INSERT INTO allowance_matrix (allowance_type, allowance_name, amount, amount_type, eligibility_criteria, department, employment_type, effective_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($allowance);
        }
    }

    // Insert sample compensation rules
    $rules = [
        [
            'Night Shift Differential',
            'Shift',
            'Additional compensation for employees working night shifts (10PM-6AM)',
            'All employees working night shift schedule',
            'Additional 10% of basic salary for hours worked between 10PM-6AM',
            '2024-01-01'
        ],
        [
            'Hazard Pay',
            'Department',
            'Additional compensation for employees working in hazardous conditions',
            'Engineering and Operations department employees in field roles',
            'Fixed ₱2,000 monthly additional to basic salary',
            '2024-01-01'
        ],
        [
            'Overtime Compensation',
            'Employment Type',
            'Overtime pay calculation rules for different employment types',
            'All regular and probationary employees',
            '125% of hourly rate for first 8 hours overtime, 130% for beyond 8 hours',
            '2024-01-01'
        ]
    ];

    foreach ($rules as $rule) {
        $checkSql = "SELECT COUNT(*) FROM compensation_rules WHERE rule_name = ?";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$rule[0]]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $sql = "INSERT INTO compensation_rules (rule_name, rule_type, description, eligibility_criteria, application_rules, effective_date) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($rule);
        }
    }

    // Insert sample salary movements
    $movements = [
        ['Annual Review', 101, 'Juan Dela Cruz', 'IT', 45000.00, 48000.00, 3000.00, 6.67, '2024-01-15', 'Annual performance-based increase', 'Pending'],
        ['Promotion', 102, 'Maria Santos', 'HR', 38000.00, 45000.00, 7000.00, 18.42, '2024-02-01', 'Promotion to Senior HR Specialist', 'Approved'],
        ['Merit Increase', 103, 'Pedro Reyes', 'Finance', 52000.00, 55000.00, 3000.00, 5.77, '2024-01-20', 'Exceptional performance rating', 'Pending'],
        ['COLA', 104, 'Anna Lopez', 'Marketing', 42000.00, 43680.00, 1680.00, 4.00, '2024-03-01', 'Cost of Living Adjustment', 'Approved']
    ];

    foreach ($movements as $movement) {
        $checkSql = "SELECT COUNT(*) FROM salary_movements WHERE employee_id = ? AND movement_type = ? AND effective_date = ?";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$movement[1], $movement[0], $movement[8]]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $sql = "INSERT INTO salary_movements (movement_type, employee_id, employee_name, department, previous_salary, new_salary, increase_amount, increase_percentage, effective_date, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($movement);
        }
    }

    // Insert sample bonus structures
    $bonuses = [
        ['Performance', 'Q4 Performance Bonus', 'Percentage', 'All employees with minimum 6 months service and meets performance targets', 0.00, 15.00, 'Basic Salary × Performance Rating × 0.15', 'End of Quarter'],
        ['13th Month', '13th Month Pay', 'Fixed Amount', 'All employees with at least 1 month service', 30000.00, 0.00, '', 'December'],
        ['Attendance', 'Perfect Attendance Bonus', 'Fixed Amount', 'Employees with no absences and tardiness for the quarter', 5000.00, 0.00, '', 'Quarterly'],
        ['Productivity', 'Team Productivity Bonus', 'Tiered', 'Teams exceeding productivity targets by 10% or more', 0.00, 0.00, 'Base Amount × Team Performance Multiplier', 'Monthly']
    ];

    foreach ($bonuses as $bonus) {
        $checkSql = "SELECT COUNT(*) FROM bonus_structures WHERE bonus_name = ?";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$bonus[1]]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $sql = "INSERT INTO bonus_structures (bonus_type, bonus_name, calculation_method, eligibility_criteria, amount, percentage, formula, payment_schedule) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bonus);
        }
    }
}

// Function implementations
function addSalaryGrade($pdo, $data)
{
    try {
        $sql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['grade_level'],
            $data['grade_name'],
            $data['min_salary'],
            $data['mid_salary'],
            $data['max_salary'],
            $data['step_count'],
            $data['description'],
            'Active'
        ]);
        $_SESSION['success_message'] = "Salary grade added successfully!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding salary grade: " . $e->getMessage();
        return false;
    }
}

function updateSalaryGrade($pdo, $data)
{
    try {
        $sql = "UPDATE salary_grades SET grade_name = ?, min_salary = ?, mid_salary = ?, max_salary = ?, step_count = ?, description = ?, status = ? WHERE grade_level = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['grade_name'],
            $data['min_salary'],
            $data['mid_salary'],
            $data['max_salary'],
            $data['step_count'],
            $data['description'],
            $data['status'],
            $data['grade_level']
        ]);
        $_SESSION['success_message'] = "Salary grade updated successfully!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating salary grade: " . $e->getMessage();
        return false;
    }
}

function addAllowance($pdo, $data)
{
    try {
        $sql = "INSERT INTO allowance_matrix (allowance_type, allowance_name, amount, amount_type, eligibility_criteria, department, employment_type, effective_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['allowance_type'],
            $data['allowance_name'],
            $data['amount'],
            $data['amount_type'],
            $data['eligibility_criteria'],
            $data['department'],
            $data['employment_type'],
            $data['effective_date'],
            'Active'
        ]);
        $_SESSION['success_message'] = "Allowance added successfully!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding allowance: " . $e->getMessage();
        return false;
    }
}

function updateAllowance($pdo, $data)
{
    try {
        $sql = "UPDATE allowance_matrix SET allowance_type = ?, amount = ?, amount_type = ?, eligibility_criteria = ?, department = ?, employment_type = ?, effective_date = ?, status = ? WHERE allowance_name = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['allowance_type'],
            $data['amount'],
            $data['amount_type'],
            $data['eligibility_criteria'],
            $data['department'],
            $data['employment_type'],
            $data['effective_date'],
            $data['status'],
            $data['allowance_name']
        ]);
        $_SESSION['success_message'] = "Allowance updated successfully!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating allowance: " . $e->getMessage();
        return false;
    }
}

function addCompensationRule($pdo, $data)
{
    try {
        $sql = "INSERT INTO compensation_rules (rule_name, rule_type, description, eligibility_criteria, application_rules, effective_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['rule_name'],
            $data['rule_type'],
            $data['description'],
            $data['eligibility_criteria'],
            $data['application_rules'],
            $data['effective_date'],
            'Active'
        ]);
        $_SESSION['success_message'] = "Compensation rule added successfully!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding compensation rule: " . $e->getMessage();
        return false;
    }
}

function addSalaryMovement($pdo, $data)
{
    try {
        $increase_amount = $data['new_salary'] - $data['previous_salary'];
        $increase_percentage = ($increase_amount / $data['previous_salary']) * 100;

        $sql = "INSERT INTO salary_movements (movement_type, employee_id, employee_name, department, previous_salary, new_salary, increase_amount, increase_percentage, effective_date, reason, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['movement_type'],
            $data['employee_id'],
            $data['employee_name'],
            $data['department'],
            $data['previous_salary'],
            $data['new_salary'],
            $increase_amount,
            round($increase_percentage, 2),
            $data['effective_date'],
            $data['reason'],
            'Pending'
        ]);
        $_SESSION['success_message'] = "Salary movement request submitted successfully!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error submitting salary movement: " . $e->getMessage();
        return false;
    }
}

function addBonusStructure($pdo, $data)
{
    try {
        $sql = "INSERT INTO bonus_structures (bonus_type, bonus_name, calculation_method, eligibility_criteria, amount, percentage, formula, payment_schedule, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['bonus_type'],
            $data['bonus_name'],
            $data['calculation_method'],
            $data['eligibility_criteria'],
            $data['amount'] ?? null,
            $data['percentage'] ?? null,
            $data['formula'] ?? null,
            $data['payment_schedule'],
            'Active'
        ]);
        $_SESSION['success_message'] = "Bonus structure added successfully!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding bonus structure: " . $e->getMessage();
        return false;
    }
}

// Data fetching functions
function getCompensationStats($pdo)
{
    try {
        $stats_stmt = $pdo->query("
            SELECT 
                (SELECT COUNT(*) FROM salary_grades WHERE status = 'Active') as active_grades,
                (SELECT COUNT(*) FROM allowance_matrix WHERE status = 'Active') as active_allowances,
                (SELECT COUNT(*) FROM compensation_rules WHERE status = 'Active') as active_rules,
                (SELECT COUNT(*) FROM salary_movements WHERE status = 'Pending') as pending_movements,
                (SELECT COUNT(*) FROM bonus_structures WHERE status = 'Active') as active_bonuses,
                (SELECT AVG(mid_salary) FROM salary_grades WHERE status = 'Active') as avg_mid_salary
        ");
        return $stats_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching compensation stats: " . $e->getMessage());
        return [
            'active_grades' => 0,
            'active_allowances' => 0,
            'active_rules' => 0,
            'pending_movements' => 0,
            'active_bonuses' => 0,
            'avg_mid_salary' => 0
        ];
    }
}
