<?php
$logFile = __DIR__ . '/reset_log.txt';
file_put_contents($logFile, "Starting reset...\n");

$host = 'localhost';
$dbname = 'dummy_hr4';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    file_put_contents($logFile, "Connected to DB\n", FILE_APPEND);

    // Disable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // Truncate tables
    $tables = [
        'employees',
        'family_dependents',
        'emergency_contacts',
        'salary_history',
        'disciplinary_cases',
        'performance_reviews',
        'employee_documents',
        'work_experience',
        'educational_background',
        'seminars',
        'skills'
    ];

    foreach ($tables as $table) {
        try {
            $pdo->exec("TRUNCATE TABLE `$table`");
            file_put_contents($logFile, "Truncated $table\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents($logFile, "Failed truncate $table: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // Insert 1 Employee
    $sql = "INSERT INTO employees (
        id, employee_id, name, email, phone, address, 
        birth_date, age, gender, civil_status, nationality,
        department, job_title, status, date_hired, date_regularized, contract,
        sss_no, tin_no, philhealth_no, pagibig_no,
        salary, pay_grade, bank_name, bank_account_no,
        hmo_provider, hmo_number,
        leave_credits_vacation, leave_credits_sick
    ) VALUES (
        1, 'EMP0001', 'Juan Dela Cruz', 'juan.delacruz@slatefreight.com', '0917-123-4567', '123 Rizal St, Makati City',
        '1990-05-15', 34, 'Male', 'Married', 'Filipino',
        'Logistics', 'Senior Logistics Officer', 'Active', '2020-01-10', '2020-07-10', 'Regular',
        '12-3456789-0', '123-456-789-000', '12-345678901-2', '1234-5678-9012',
        45000.00, 'L4', 'BDO', '10987654321',
        'Maxicare', '1122334455',
        15, 15
    )";
    $pdo->exec($sql);
    file_put_contents($logFile, "Inserted Employee\n", FILE_APPEND);

    // Insert Emergency Contact
    $pdo->exec("INSERT INTO emergency_contacts (employee_id, contact_name, relationship, phone) VALUES 
    (1, 'Maria Dela Cruz', 'Spouse', '0917-987-6543')");

    // Insert Dependents
    $pdo->exec("INSERT INTO family_dependents (employee_id, name, relationship, birth_date, contact_number) VALUES 
    (1, 'Maria Dela Cruz', 'Spouse', '1992-08-20', '0917-987-6543'),
    (1, 'Jose Dela Cruz', 'Child', '2018-02-14', '')");

    // Insert Salary History
    $pdo->exec("INSERT INTO salary_history (employee_id, amount, effective_date, type, remarks) VALUES 
    (1, 35000.00, '2020-01-10', 'Starting Salary', 'Initial Offer'),
    (1, 40000.00, '2021-01-10', 'Merit Increase', 'Performance Review 2020'),
    (1, 45000.00, '2022-01-10', 'Promotion', 'Senior designation')");

    // Insert Work Experience
    $pdo->exec("INSERT INTO work_experience (employee_id, company_name, position, date_from, date_to, is_current) VALUES 
    (1, 'ABC Transport', 'Logistics Assistant', '2015-06-01', '2019-12-31', 0),
    (1, 'XYZ Logistics', 'Dispatcher', '2013-05-01', '2015-05-30', 0)");

    // Insert Education
    $pdo->exec("INSERT INTO educational_background (employee_id, school_name, level, course, year_graduated) VALUES 
    (1, 'University of the Philippines', 'Bachelor', 'BS Business Administration', 2013),
    (1, 'Makati High School', 'High School', '', 2009)");

    // Insert Performance Reviews
    $pdo->exec("INSERT INTO performance_reviews (employee_id, review_date, rating, evaluator, comments) VALUES 
    (1, '2021-01-05', '4.5/5', 'Pedro Santos', 'Excellent performance, showed initiative in optimizing routes.'),
    (1, '2022-01-10', '4.8/5', 'Pedro Santos', 'Outstanding leadership skills.')");

    // Insert Disciplinary Cases
    $pdo->exec("INSERT INTO disciplinary_cases (employee_id, violation, description, action_taken, date_reported, date_closed, status) VALUES 
    (1, 'Late Arrival', 'Arrived 30 mins late due to heavy traffic', 'Verbal Warning', '2020-03-15', '2020-03-16', 'Closed')");

    // Re-enable checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    file_put_contents($logFile, "Done completely.\n", FILE_APPEND);

} catch (Exception $e) {
    file_put_contents($logFile, "Error: " . $e->getMessage() . "\n", FILE_APPEND);
}
