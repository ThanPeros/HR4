<?php
// hmo-benefits/HMO-plan.php
session_start();
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// Initialize Theme
$currentTheme = $_SESSION['theme'] ?? 'light';

// --- Database Migration & Setup ---

// 1. Providers Table (Enhanced with more contact details)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hmo_providers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `provider_name` varchar(100) NOT NULL,
        `provider_type` enum('HMO','Insurance','TPA','Clinic Network','Hospital') DEFAULT 'HMO',
        `contact_person` varchar(100) DEFAULT NULL,
        `contact_email` varchar(100) DEFAULT NULL,
        `contact_number` varchar(50) DEFAULT NULL,
        `alternate_number` varchar(50) DEFAULT NULL,
        `website` varchar(255) DEFAULT NULL,
        `portal_url` varchar(255) DEFAULT NULL,
        `client_services_email` varchar(100) DEFAULT NULL,
        `claims_email` varchar(100) DEFAULT NULL,
        `emergency_hotline` varchar(50) DEFAULT NULL,
        `address` text DEFAULT NULL,
        `coverage_areas` text DEFAULT NULL,
        `accreditation_date` date DEFAULT NULL,
        `status` enum('Active','Inactive','Pending') DEFAULT 'Active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `provider_name` (`provider_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log("Error creating hmo_providers table: " . $e->getMessage());
}

// 1.1 Provider Table Migrations (For existing tables)
try {
    $provider_cols = [
        "provider_type ENUM('HMO','Insurance','TPA','Clinic Network','Hospital') DEFAULT 'HMO'",
        "portal_url VARCHAR(255)",
        "client_services_email VARCHAR(100)",
        "claims_email VARCHAR(100)",
        "emergency_hotline VARCHAR(50)",
        "coverage_areas TEXT",
        "accreditation_date DATE",
        "alternate_number VARCHAR(50)"
    ];
    
    foreach($provider_cols as $p_col) {
        // Extract column name safely (handle ENUM and others)
        $p_col_parts = explode(' ', trim($p_col));
        $p_col_name = $p_col_parts[0];
        
        $p_check = $pdo->query("SHOW COLUMNS FROM hmo_providers LIKE '$p_col_name'")->fetch();
        if(!$p_check) {
            try { 
                $pdo->exec("ALTER TABLE hmo_providers ADD COLUMN $p_col"); 
            } catch(Exception $e) {
                error_log("Error adding column $p_col_name: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Error in provider table migrations: " . $e->getMessage());
}

// 2. Plans Table (Enhanced with realistic annual limits and coverage details)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `hmo_plans` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `plan_name` varchar(200) NOT NULL,
        `provider_id` int(11) NOT NULL,
        `plan_code` varchar(50) DEFAULT NULL,
        `plan_category` enum('Medical', 'Life', 'Dental', 'Vision', 'Disability', 'Maternity', 'Comprehensive') DEFAULT 'Medical',
        
        -- Annual Coverage Limits (Realistic Philippine HMO ranges)
        `annual_limit` decimal(15,2) DEFAULT 100000.00,
        `outpatient_limit` decimal(15,2) DEFAULT 20000.00,
        `emergency_limit` decimal(15,2) DEFAULT 50000.00,
        `room_accommodation_limit` decimal(15,2) DEFAULT 3000.00,
        `professional_fees_limit` decimal(15,2) DEFAULT 50000.00,
        
        -- Per Illness/Procedure Limits
        `per_illness_limit` decimal(15,2) DEFAULT 50000.00,
        `surgical_procedure_limit` decimal(15,2) DEFAULT 40000.00,
        
        -- Special Limits
        `maternity_coverage` decimal(15,2) DEFAULT 50000.00,
        `dental_coverage` decimal(15,2) DEFAULT 10000.00,
        `optical_coverage` decimal(15,2) DEFAULT 5000.00,
        
        -- Coverage Details
        `room_type` enum('Ward','Semi-Private','Private','Suite','Deluxe') DEFAULT 'Ward',
        `coverage_days_per_year` int(11) DEFAULT 45,
        `mbld_coverage` tinyint(1) DEFAULT 0,
        `mbld_amount` decimal(15,2) DEFAULT 0,
        `preventive_care` tinyint(1) DEFAULT 0,
        `preventive_care_limit` decimal(15,2) DEFAULT 5000.00,
        
        -- Financials
        `total_premium` decimal(15,2) NOT NULL,
        `employer_share` decimal(15,2) NOT NULL,
        `employee_share` decimal(15,2) NOT NULL,
        `additional_dependent_premium` decimal(15,2) DEFAULT 0,
        
        -- Administrative
        `frequency` enum('Monthly','Quarterly','Semi-Annual','Annual') DEFAULT 'Monthly',
        `effective_date` date DEFAULT NULL,
        `expiry_date` date DEFAULT NULL,
        `renewal_date` date DEFAULT NULL,
        `waiting_period_days` int(11) DEFAULT 30,
        `grace_period_days` int(11) DEFAULT 15,
        
        -- Contact & Documentation
        `plan_contact` varchar(100) DEFAULT NULL,
        `plan_email` varchar(100) DEFAULT NULL,
        `plan_website` varchar(255) DEFAULT NULL,
        `plan_portal_url` varchar(255) DEFAULT NULL,
        `plan_contract_file` varchar(255) DEFAULT NULL,
        `plan_brochure_file` varchar(255) DEFAULT NULL,
        `plan_terms_file` varchar(255) DEFAULT NULL,
        
        -- Additional Details
        `network_info` text DEFAULT NULL,
        `inclusions` text DEFAULT NULL,
        `exclusions` text DEFAULT NULL,
        `special_conditions` text DEFAULT NULL,
        
        `status` enum('Active','Inactive','Archived','Renewal Pending') DEFAULT 'Active',
        `description` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        
        PRIMARY KEY (`id`),
        KEY `idx_provider` (`provider_id`),
        KEY `idx_status` (`status`),
        KEY `idx_category` (`plan_category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log("Error creating hmo_plans table: " . $e->getMessage());
}

// 2.1 Add new columns to hmo_plans if they don't exist
try {
    $columns_to_add = [
        "provider_type ENUM('HMO','Insurance','TPA','Clinic Network','Hospital') DEFAULT 'HMO'",
        "room_accommodation_limit DECIMAL(15,2) DEFAULT 3000.00",
        "professional_fees_limit DECIMAL(15,2) DEFAULT 50000.00",
        "per_illness_limit DECIMAL(15,2) DEFAULT 50000.00",
        "surgical_procedure_limit DECIMAL(15,2) DEFAULT 40000.00",
        "coverage_days_per_year INT DEFAULT 45",
        "mbld_coverage TINYINT(1) DEFAULT 0",
        "mbld_amount DECIMAL(15,2) DEFAULT 0",
        "preventive_care_limit DECIMAL(15,2) DEFAULT 5000.00",
        "additional_dependent_premium DECIMAL(15,2) DEFAULT 0",
        "grace_period_days INT DEFAULT 15",
        "plan_portal_url VARCHAR(255)",
        "plan_brochure_file VARCHAR(255)",
        "plan_terms_file VARCHAR(255)",
        "renewal_date DATE",
        "inclusions TEXT",
        "exclusions TEXT",
        "special_conditions TEXT"
    ];
    
    foreach($columns_to_add as $col) {
        $col_name = explode(' ', $col)[0];
        $check = $pdo->query("SHOW COLUMNS FROM hmo_plans LIKE '$col_name'")->fetch();
        if(!$check) {
            try { 
                $pdo->exec("ALTER TABLE hmo_plans ADD COLUMN $col"); 
            } catch(Exception $e) {
                error_log("Error adding column $col_name to hmo_plans: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Error in hmo_plans migrations: " . $e->getMessage());
}

// 3. Provider Contacts Table (Multiple contacts per provider)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `provider_contacts` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `provider_id` int(11) NOT NULL,
        `contact_type` enum('Account Manager','Sales','Claims','Client Services','Billing','Emergency','Technical') DEFAULT 'Account Manager',
        `contact_person` varchar(100) NOT NULL,
        `contact_email` varchar(100) DEFAULT NULL,
        `contact_number` varchar(50) DEFAULT NULL,
        `alternate_number` varchar(50) DEFAULT NULL,
        `department` varchar(100) DEFAULT NULL,
        `is_primary` tinyint(1) DEFAULT 0,
        `notes` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_provider` (`provider_id`),
        KEY `idx_type` (`contact_type`),
        CONSTRAINT `fk_provider_contacts_provider` FOREIGN KEY (`provider_id`) REFERENCES `hmo_providers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log("Error creating provider_contacts table: " . $e->getMessage());
}

// 4. Plan Documents Table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `plan_documents` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `plan_id` int(11) NOT NULL,
        `document_type` enum('Contract','Brochure','Terms','Certificate','Schedule of Benefits','Renewal Notice','Other') DEFAULT 'Contract',
        `document_name` varchar(255) NOT NULL,
        `file_path` varchar(255) NOT NULL,
        `file_size` int(11) DEFAULT NULL,
        `uploaded_by` int(11) DEFAULT NULL,
        `upload_date` date DEFAULT NULL,
        `expiry_date` date DEFAULT NULL,
        `description` text DEFAULT NULL,
        `is_active` tinyint(1) DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_plan` (`plan_id`),
        KEY `idx_type` (`document_type`),
        CONSTRAINT `fk_plan_documents_plan` FOREIGN KEY (`plan_id`) REFERENCES `hmo_plans` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log("Error creating plan_documents table: " . $e->getMessage());
}

// 5. Enrollment Table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_hmo_enrollments` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `employee_id` int(11) NOT NULL,
        `plan_id` int(11) NOT NULL,
        `enrollment_date` date NOT NULL,
        `effective_date` date DEFAULT NULL,
        `card_number` varchar(50) DEFAULT NULL,
        `card_issued_date` date DEFAULT NULL,
        `card_expiry_date` date DEFAULT NULL,
        `status` enum('Pending', 'Active', 'Suspended', 'Expired', 'Cancelled', 'Renewal Due') DEFAULT 'Pending',
        `dependent_count` int(11) DEFAULT 0,
        `total_covered` int(11) DEFAULT 1,
        `remarks` text DEFAULT NULL,
        `approved_by` int(11) DEFAULT NULL,
        `approved_date` date DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_employee` (`employee_id`),
        KEY `idx_plan` (`plan_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log("Error creating employee_hmo_enrollments table: " . $e->getMessage());
}

// 5.1 Enrollment Migrations
try {
    $enrollment_cols = [
        "effective_date DATE",
        "card_number VARCHAR(50)",
        "card_issued_date DATE",
        "card_expiry_date DATE",
        "total_covered INT DEFAULT 1",
        "remarks TEXT",
        "approved_by INT",
        "approved_date DATE"
    ];
    
    foreach($enrollment_cols as $e_col) {
        $e_col_parts = explode(' ', trim($e_col));
        $e_col_name = $e_col_parts[0];
        
        $e_check = $pdo->query("SHOW COLUMNS FROM employee_hmo_enrollments LIKE '$e_col_name'")->fetch();
        if(!$e_check) {
            try { 
                $pdo->exec("ALTER TABLE employee_hmo_enrollments ADD COLUMN $e_col"); 
            } catch(Exception $e) {
                error_log("Error adding column $e_col_name to enrollments: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Error in enrollment migrations: " . $e->getMessage());
}

// 6. Dependents Table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `enrolled_dependents` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `enrollment_id` int(11) NOT NULL,
        `dependent_type` enum('Spouse','Child','Parent','Sibling','Other') DEFAULT 'Child',
        `name` varchar(100) NOT NULL,
        `relationship` varchar(50) NOT NULL,
        `birthdate` date DEFAULT NULL,
        `age` int(11) DEFAULT NULL,
        `card_number` varchar(50) DEFAULT NULL,
        `status` enum('Active','Inactive','Age Out','Other') DEFAULT 'Active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_enrollment` (`enrollment_id`),
        KEY `idx_status` (`status`),
        CONSTRAINT `fk_enrolled_dependents_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `employee_hmo_enrollments` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log("Error creating enrolled_dependents table: " . $e->getMessage());
}

// 6.1 Dependents Migrations
try {
    $dep_cols = [
        "dependent_type ENUM('Spouse','Child','Parent','Sibling','Other') DEFAULT 'Child'",
        "relationship VARCHAR(50)",
        "birthdate DATE",
        "age INT",
        "status ENUM('Active','Inactive','Age Out','Other') DEFAULT 'Active'"
    ];
    
    foreach($dep_cols as $d_col) {
        $d_col_parts = explode(' ', trim($d_col));
        $d_col_name = $d_col_parts[0];
        
        $d_check = $pdo->query("SHOW COLUMNS FROM enrolled_dependents LIKE '$d_col_name'")->fetch();
        if(!$d_check) {
            try { 
                $pdo->exec("ALTER TABLE enrolled_dependents ADD COLUMN $d_col"); 
            } catch(Exception $e) {
                error_log("Error adding column $d_col_name to enrolled_dependents: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Error in dependents migrations: " . $e->getMessage());
}

// Insert sample providers if table is empty
try {
    $checkProviders = $pdo->query("SELECT COUNT(*) as count FROM hmo_providers")->fetch();
    if($checkProviders['count'] == 0) {
        $sampleProviders = [
            ['Maxicare Healthcare Corporation', 'HMO', 'Juan Santos', 'support@maxicare.com.ph', '0288810777', 'https://www.maxicare.com.ph', 'https://member.maxicare.com.ph', 'clientcare@maxicare.com.ph', 'claims@maxicare.com.ph', '0288810777', '9th Floor, Maxicare Center, San Miguel Ave, Pasig City', 'Nationwide', '2024-01-01'],
            ['MediCard Philippines, Inc.', 'HMO', 'Maria Gonzales', 'info@medicardphils.com', '0288400700', 'https://www.medicardphils.com', 'https://my.medicardphils.com', 'customerservice@medicardphils.com', 'claims@medicardphils.com', '0288400700', 'MediCard Center, Ortigas Center, Pasig City', 'Nationwide', '2024-01-01'],
            ['Intellicare', 'HMO', 'Robert Lim', 'helpdesk@intellicare.com.ph', '0288722600', 'https://www.intellicare.com.ph', 'https://member.intellicare.com.ph', 'service@intellicare.com.ph', 'claims@intellicare.com.ph', '0288722600', '11/F BDO Towers Valero, Valero St, Makati City', 'Nationwide', '2024-01-01'],
            ['Philam Life', 'Insurance', 'Anna Reyes', 'service@philamlife.com', '0285242000', 'https://www.philamlife.com', 'https://client.philamlife.com', 'customercare@philamlife.com', 'claims@philamlife.com', '0285242000', 'Philam Life Centre, UN Ave, Manila', 'Nationwide', '2024-01-01'],
            ['Sun Life Grepa Financial, Inc.', 'Insurance', 'Michael Tan', 'customer.service@sunlife.com', '0288499888', 'https://www.sunlife.com.ph', 'https://mysunlife.sunlife.com.ph', 'service@sunlife.com.ph', 'claims@sunlife.com.ph', '0288499888', 'Sun Life Centre, 5th Avenue, Taguig City', 'Nationwide', '2024-01-01']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO hmo_providers (provider_name, provider_type, contact_person, contact_email, contact_number, website, portal_url, client_services_email, claims_email, emergency_hotline, address, coverage_areas, accreditation_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        
        foreach($sampleProviders as $provider) {
            $stmt->execute($provider);
        }
    }
} catch (Exception $e) {
    error_log("Error inserting sample providers: " . $e->getMessage());
}

// Insert sample plans if table is empty
try {
    $checkPlans = $pdo->query("SELECT COUNT(*) as count FROM hmo_plans")->fetch();
    if($checkPlans['count'] == 0) {
        // Get provider IDs
        $providers = $pdo->query("SELECT id, provider_name FROM hmo_providers ORDER BY id LIMIT 5")->fetchAll();
        
        if(count($providers) > 0) {
            $samplePlans = [
                ['Gold Health Plus', 'GHP-2024', $providers[0]['id'], 'Medical', 150000.00, 30000.00, 75000.00, 5000.00, 60000.00, 75000.00, 50000.00, 60000.00, 15000.00, 8000.00, 'Semi-Private', 60, 3500.00, 2800.00, 700.00, 2000.00, 'Monthly', '2024-01-01', '2024-12-31', '2024-11-01', 30, 15, 'John Smith', 'john@maxicare.com', 'https://www.maxicare.com.ph/gold-plan', 'https://portal.maxicare.com/gold'],
                ['Silver Basic', 'SB-2024', $providers[0]['id'], 'Medical', 100000.00, 20000.00, 50000.00, 3000.00, 50000.00, 50000.00, 40000.00, 50000.00, 10000.00, 5000.00, 'Ward', 45, 2500.00, 2000.00, 500.00, 1500.00, 'Monthly', '2024-01-01', '2024-12-31', '2024-11-01', 30, 15, 'Maria Santos', 'maria@maxicare.com', 'https://www.maxicare.com.ph/silver-plan', 'https://portal.maxicare.com/silver'],
                ['Dental Care Premium', 'DCP-2024', $providers[1]['id'], 'Dental', 20000.00, 20000.00, 10000.00, 0.00, 0.00, 20000.00, 0.00, 0.00, 20000.00, 0.00, 'N/A', 365, 800.00, 800.00, 0.00, 400.00, 'Monthly', '2024-01-01', '2024-12-31', '2024-11-01', 30, 15, 'Dr. James Lee', 'james@medicard.com', 'https://www.medicardphils.com/dental', 'https://my.medicardphils.com/dental'],
                ['Vision Care', 'VC-2024', $providers[2]['id'], 'Vision', 10000.00, 10000.00, 5000.00, 0.00, 0.00, 10000.00, 0.00, 0.00, 0.00, 10000.00, 'N/A', 365, 500.00, 500.00, 0.00, 300.00, 'Monthly', '2024-01-01', '2024-12-31', '2024-11-01', 30, 15, 'Optom. Sarah Chen', 'sarah@intellicare.com', 'https://www.intellicare.com/vision', 'https://member.intellicare.com/vision'],
                ['Life Insurance Basic', 'LIB-2024', $providers[3]['id'], 'Life', 500000.00, 0.00, 0.00, 0.00, 0.00, 500000.00, 0.00, 0.00, 0.00, 0.00, 'N/A', 365, 1000.00, 1000.00, 0.00, 0.00, 'Monthly', '2024-01-01', '2024-12-31', '2024-11-01', 30, 15, 'Robert Lim Jr.', 'robert@philamlife.com', 'https://www.philamlife.com/life-basic', 'https://client.philamlife.com/basic']
            ];
            
            $stmt = $pdo->prepare("INSERT INTO hmo_plans (plan_name, plan_code, provider_id, plan_category, annual_limit, outpatient_limit, emergency_limit, room_accommodation_limit, professional_fees_limit, per_illness_limit, surgical_procedure_limit, maternity_coverage, dental_coverage, optical_coverage, room_type, coverage_days_per_year, total_premium, employer_share, employee_share, additional_dependent_premium, frequency, effective_date, expiry_date, renewal_date, waiting_period_days, grace_period_days, plan_contact, plan_email, plan_website, plan_portal_url, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
            
            foreach($samplePlans as $plan) {
                $stmt->execute($plan);
            }
        }
    }
} catch (Exception $e) {
    error_log("Error inserting sample plans: " . $e->getMessage());
}

// Insert sample provider contacts if table is empty
try {
    $checkContacts = $pdo->query("SELECT COUNT(*) as count FROM provider_contacts")->fetch();
    if($checkContacts['count'] == 0) {
        // Get provider IDs
        $providers = $pdo->query("SELECT id, provider_name FROM hmo_providers ORDER BY id")->fetchAll();
        
        if(count($providers) > 0) {
            $sampleContacts = [
                [$providers[0]['id'], 'Account Manager', 'Juan Santos', 'jsantos@maxicare.com.ph', '09171234567', '09181234567', 'Client Relations', 1, 'Primary contact for corporate accounts'],
                [$providers[0]['id'], 'Claims', 'Maria Reyes', 'claims@maxicare.com.ph', '0288810777', '09191234567', 'Claims Department', 0, 'For claims submission and inquiries'],
                [$providers[1]['id'], 'Account Manager', 'Carlos Lim', 'clim@medicardphils.com', '09201234567', '09211234567', 'Account Management', 1, 'Main contact for MediCard plans'],
                [$providers[2]['id'], 'Client Services', 'Sarah Johnson', 'sarah@intellicare.com.ph', '09221234567', '09231234567', 'Customer Service', 1, '24/7 client support']
            ];
            
            $stmt = $pdo->prepare("INSERT INTO provider_contacts (provider_id, contact_type, contact_person, contact_email, contact_number, alternate_number, department, is_primary, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach($sampleContacts as $contact) {
                $stmt->execute($contact);
            }
        }
    }
} catch (Exception $e) {
    error_log("Error inserting sample contacts: " . $e->getMessage());
}

// --- Data Handling ---

// Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ENROLL EMPLOYEE
    if (isset($_POST['enroll_employee'])) {
        $employeeId = $_POST['employee_id'];
        $planId = $_POST['plan_id'];
        $enrollmentDate = $_POST['enrollment_date'];
        $effectiveDate = $_POST['effective_date'] ?? $enrollmentDate;
        $cardNumber = $_POST['card_number'] ?? '';
        $dependentNames = $_POST['dependent_name'] ?? [];
        $dependentRelationships = $_POST['dependent_relationship'] ?? [];
        $dependentBirthdates = $_POST['dependent_birthdate'] ?? [];
        
        try {
            $pdo->beginTransaction();
            
            // Get plan details for effective date calculation
            $planStmt = $pdo->prepare("SELECT waiting_period_days FROM hmo_plans WHERE id = ?");
            $planStmt->execute([$planId]);
            $plan = $planStmt->fetch();
            
            // Calculate effective date if not provided (add waiting period)
            if(empty($_POST['effective_date'])) {
                $effectiveDate = date('Y-m-d', strtotime($enrollmentDate . ' + ' . ($plan['waiting_period_days'] ?? 30) . ' days'));
            }
            
            // Calculate card expiry (typically 1 year from effective date)
            $cardExpiry = date('Y-m-d', strtotime($effectiveDate . ' + 1 year'));
            
            $stmt = $pdo->prepare("INSERT INTO employee_hmo_enrollments 
                (employee_id, plan_id, enrollment_date, effective_date, card_number, card_expiry_date, status, dependent_count, total_covered) 
                VALUES (?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
            
            $dependentCount = count($dependentNames);
            $totalCovered = 1 + $dependentCount; // Employee + dependents
            
            $stmt->execute([
                $employeeId, 
                $planId, 
                $enrollmentDate, 
                $effectiveDate,
                $cardNumber,
                $cardExpiry,
                $dependentCount,
                $totalCovered
            ]);
            
            $enrollmentId = $pdo->lastInsertId();
            
            // Sync with Dashboard Analytics Table (hmo_enrollments)
            // Ensures data reflects in the main dashboard automatically
            try {
                $checkTable = $pdo->query("SHOW TABLES LIKE 'hmo_enrollments'")->fetchColumn();
                if ($checkTable) {
                    // Remove old entry to avoid duplicates if strict mode
                    $delSync = $pdo->prepare("DELETE FROM hmo_enrollments WHERE employee_id = ?");
                    $delSync->execute([$employeeId]);
                    
                    // Insert new entry
                    $syncStmt = $pdo->prepare("INSERT INTO hmo_enrollments (employee_id, benefit_id, status, expiry_date) VALUES (?, ?, 'Active', ?)");
                    $syncStmt->execute([$employeeId, $planId, $cardExpiry]);
                }
            } catch (Exception $e) {
                // Silent fail if analytics table has issues, shouldn't block main flow
            }
            
            // Insert dependents
            foreach($dependentNames as $index => $name) {
                if(!empty($name)) {
                    $dependentStmt = $pdo->prepare("INSERT INTO enrolled_dependents 
                        (enrollment_id, dependent_type, name, relationship, birthdate, age, status) 
                        VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                    
                    $birthdate = $dependentBirthdates[$index] ?? null;
                    $age = $birthdate ? floor((time() - strtotime($birthdate)) / 31556926) : null;
                    
                    $dependentStmt->execute([
                        $enrollmentId,
                        $dependentRelationships[$index] ?? 'Other',
                        $name,
                        $dependentRelationships[$index] ?? 'Other',
                        $birthdate,
                        $age
                    ]);
                }
            }
            
            $_SESSION['success_message'] = "Employee enrolled successfully! Coverage effective from " . date('F d, Y', strtotime($effectiveDate));
            $pdo->commit();
            header("Location: HMO-plan.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error: " . $e->getMessage();
        }
    }

    // ADD NEW PLAN
    if (isset($_POST['add_plan'])) {
        try {
            // File Upload Handling
            $contractFile = '';
            $brochureFile = '';
            $termsFile = '';
            
            if(isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] == 0) {
                $contractFile = 'contracts/' . date('Ymd_') . $_FILES['contract_file']['name'];
                // move_uploaded_file($_FILES['contract_file']['tmp_name'], '../uploads/' . $contractFile);
            }
            
            if(isset($_FILES['brochure_file']) && $_FILES['brochure_file']['error'] == 0) {
                $brochureFile = 'brochures/' . date('Ymd_') . $_FILES['brochure_file']['name'];
                // move_uploaded_file($_FILES['brochure_file']['tmp_name'], '../uploads/' . $brochureFile);
            }
            
            if(isset($_FILES['terms_file']) && $_FILES['terms_file']['error'] == 0) {
                $termsFile = 'terms/' . date('Ymd_') . $_FILES['terms_file']['name'];
                // move_uploaded_file($_FILES['terms_file']['tmp_name'], '../uploads/' . $termsFile);
            }
            
            // Set realistic annual limits based on plan category
            $category = $_POST['plan_category'];
            $defaultLimits = [
                'Medical' => ['annual' => 100000, 'outpatient' => 20000, 'emergency' => 50000],
                'Dental' => ['annual' => 15000, 'outpatient' => 15000, 'emergency' => 5000],
                'Vision' => ['annual' => 8000, 'outpatient' => 8000, 'emergency' => 2000],
                'Maternity' => ['annual' => 50000, 'outpatient' => 50000, 'emergency' => 10000],
                'Life' => ['annual' => 500000, 'outpatient' => 0, 'emergency' => 0],
                'Comprehensive' => ['annual' => 250000, 'outpatient' => 50000, 'emergency' => 75000]
            ];
            
            $limits = $defaultLimits[$category] ?? $defaultLimits['Medical'];
            
            $stmt = $pdo->prepare("INSERT INTO hmo_plans (
                provider_id, plan_name, plan_category, plan_code,
                annual_limit, outpatient_limit, emergency_limit,
                room_accommodation_limit, professional_fees_limit,
                per_illness_limit, surgical_procedure_limit,
                maternity_coverage, dental_coverage, optical_coverage,
                room_type, coverage_days_per_year,
                total_premium, employer_share, employee_share, additional_dependent_premium,
                frequency, effective_date, expiry_date, renewal_date,
                waiting_period_days, grace_period_days,
                plan_contact, plan_email, plan_website, plan_portal_url,
                plan_contract_file, plan_brochure_file, plan_terms_file,
                inclusions, exclusions, special_conditions,
                description, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $_POST['provider_id'],
                $_POST['plan_name'],
                $category,
                $_POST['plan_code'] ?? '',
                $_POST['annual_limit'] ?? $limits['annual'],
                $_POST['outpatient_limit'] ?? $limits['outpatient'],
                $_POST['emergency_limit'] ?? $limits['emergency'],
                $_POST['room_limit'] ?? 3000,
                $_POST['professional_fees_limit'] ?? 50000,
                $_POST['per_illness_limit'] ?? 50000,
                $_POST['surgical_limit'] ?? 40000,
                $_POST['maternity_coverage'] ?? ($category == 'Maternity' ? 50000 : 0),
                $_POST['dental_coverage'] ?? ($category == 'Dental' ? 15000 : 0),
                $_POST['optical_coverage'] ?? ($category == 'Vision' ? 8000 : 0),
                $_POST['room_type'] ?? 'Ward',
                $_POST['coverage_days'] ?? 45,
                $_POST['total_premium'] ?? 0,
                $_POST['employer_share'] ?? 0,
                $_POST['employee_share'] ?? 0,
                $_POST['dependent_premium'] ?? 0,
                $_POST['frequency'] ?? 'Monthly',
                $_POST['effective_date'] ?? date('Y-m-d'),
                $_POST['expiry_date'] ?? date('Y-m-d', strtotime('+1 year')),
                $_POST['renewal_date'] ?? date('Y-m-d', strtotime('+11 months')),
                $_POST['waiting_period'] ?? 30,
                $_POST['grace_period'] ?? 15,
                $_POST['plan_contact'],
                $_POST['plan_email'],
                $_POST['plan_website'],
                $_POST['plan_portal'],
                $contractFile,
                $brochureFile,
                $termsFile,
                $_POST['inclusions'] ?? '',
                $_POST['exclusions'] ?? '',
                $_POST['special_conditions'] ?? '',
                $_POST['description'],
                'Active'
            ]);
            
            $planId = $pdo->lastInsertId();
            
            // Add sample documents if files were uploaded
            if($contractFile) {
                $docStmt = $pdo->prepare("INSERT INTO plan_documents (plan_id, document_type, document_name, file_path, upload_date) VALUES (?, 'Contract', ?, ?, CURDATE())");
                $docStmt->execute([$planId, 'Master Service Agreement', $contractFile]);
            }
            
            $_SESSION['success_message'] = "New Plan Added Successfully with realistic annual limits!";
            header("Location: HMO-plan.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Error adding plan: " . $e->getMessage();
        }
    }
}

// Fetch Providers with Contact Details
$providers = $pdo->query("
    SELECT p.*, 
           (SELECT contact_person FROM provider_contacts WHERE provider_id = p.id AND is_primary = 1 LIMIT 1) as primary_contact,
           (SELECT contact_number FROM provider_contacts WHERE provider_id = p.id AND is_primary = 1 LIMIT 1) as primary_contact_number
    FROM hmo_providers p 
    WHERE status='Active' 
    ORDER BY provider_name
")->fetchAll();

// Fetch All Plans with Provider Info
$allPlans = $pdo->query("
    SELECT p.*, 
           pr.provider_name, pr.provider_type,
           pr.client_services_email, pr.emergency_hotline, pr.portal_url
    FROM hmo_plans p
    JOIN hmo_providers pr ON p.provider_id = pr.id
    WHERE p.status='Active' 
    ORDER BY p.plan_category, p.plan_name
")->fetchAll();

// Fetch Plan Documents Count
$planDocsQuery = $pdo->query("SELECT plan_id, COUNT(*) as doc_count FROM plan_documents WHERE is_active = 1 GROUP BY plan_id");
$planDocCounts = [];
foreach($planDocsQuery as $row) {
    $planDocCounts[$row['plan_id']] = $row['doc_count'];
}

$plansJson = json_encode($allPlans);

// Fetch Enrollments with Details
$enrollments = $pdo->query("
    SELECT e.*, 
           p.plan_name, p.plan_category, p.annual_limit,
           pr.provider_name, pr.provider_type,
           emp.name as employee_name, emp.employee_id as emp_code
    FROM employee_hmo_enrollments e
    JOIN hmo_plans p ON e.plan_id = p.id
    JOIN hmo_providers pr ON p.provider_id = pr.id
    JOIN employees emp ON e.employee_id = emp.id
    ORDER BY e.effective_date DESC
")->fetchAll();

// Fetch Employees
// Fetch Employees with existing card numbers (if any)
$employees = $pdo->query("
    SELECT emp.id, emp.name, emp.employee_id,
        (SELECT card_number FROM employee_hmo_enrollments WHERE employee_id = emp.id AND card_number IS NOT NULL AND card_number != '' ORDER BY created_at DESC LIMIT 1) as latest_card
    FROM employees emp 
    WHERE emp.status='Active' 
    ORDER BY emp.name
")->fetchAll();

// Stats
$totalPlans = count($allPlans);
$totalEnrolled = count($enrollments);
$totalProviders = count($providers);

// Calculate total coverage amount
$totalCoverage = 0;
foreach($enrollments as $enr) {
    $totalCoverage += $enr['annual_limit'] * (1 + $enr['dependent_count']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMO & Insurance | HR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
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
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            margin-top: 60px;
        }
        
        .main-content { padding: 2rem; min-height: 100vh; }
        
        .report-card {
            background: white; border-radius: var(--border-radius); padding: 1.5rem;
            box-shadow: var(--shadow); border-left: 4px solid var(--primary-color);
            margin-bottom: 1.5rem;
        }
        
        .report-card-header { 
            border-bottom: 1px solid #e3e6f0; 
            padding-bottom: 1rem; 
            margin-bottom: 1rem; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        
        .report-card-title { 
            font-size: 1.1rem; 
            font-weight: 600; 
            margin: 0; 
        }
        
        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        
        .data-table th { 
            background: #f8f9fc; 
            padding: 0.75rem; 
            color: #4e73df; 
            font-size: 0.85rem; 
            text-transform: uppercase; 
        }
        
        .data-table td { 
            padding: 0.75rem; 
            border-bottom: 1px solid #e3e6f0; 
        }
        
        .data-table tr:hover { 
            background-color: #f8f9fc; 
        }
        
        .coverage-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .plan-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        .modal-xl-custom {
            max-width: 1200px;
        }
        
        .document-badge {
            cursor: pointer;
        }
        
        .limit-badge {
            background-color: #e3f2fd;
            color: #1565c0;
            border: 1px solid #90caf9;
        }
    </style>
</head>
<body>

    <div class="main-content">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-hospital-user"></i> HMO & Insurance Management</h1>
                <p class="text-muted mb-0">Manage medical, dental, vision plans and employee enrollments</p>
            </div>
            <div class="d-flex gap-2">

                 <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollModal">
                    <i class="fas fa-user-plus"></i> Enroll Employee
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
         <?php if(isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="border-left: 5px solid #4e73df !important;">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Available Plans</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalPlans; ?></div>
                                <small class="text-muted">Medical, Dental, Vision</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-file-medical fa-2x text-gray-300" style="color: #d1d3e2;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="border-left: 5px solid #1cc88a !important;">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Providers</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalProviders; ?></div>
                                <small class="text-muted">HMO Partners</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-handshake fa-2x text-gray-300" style="color: #d1d3e2;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="border-left: 5px solid #36b9cc !important;">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Active Enrollments</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $totalEnrolled; ?></div>
                                <small class="text-muted">Covered Employees</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-users fa-2x text-gray-300" style="color: #d1d3e2;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-uppercase mb-1" style="opacity: 0.8;">Total Coverage</div>
                                <div class="h5 mb-0 font-weight-bold">₱<?php echo number_format($totalCoverage, 2); ?></div>
                                <small style="opacity: 0.8;">Annual Limit Value</small>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-shield-alt fa-2x" style="opacity: 0.5;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="row">
            <!-- Providers List -->
            <div class="col-12 mb-4">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Providers & Contact Information</h3>
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addProviderModal">
                            <i class="fas fa-plus"></i> Add Provider
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" id="providersTable">
                            <thead>
                                <tr>
                                    <th>Provider</th>
                                    <th>Type</th>
                                    <th>Primary Contact</th>

                                    <th>Plans</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($providers as $prov): 
                                    $providerPlans = array_filter($allPlans, function($plan) use ($prov) {
                                        return $plan['provider_id'] == $prov['id'];
                                    });
                                    $planCount = count($providerPlans);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prov['provider_name']); ?></strong><br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($prov['address'] ?? ''); ?>
                                            <?php if($prov['accreditation_date']): ?>
                                                <br>Accredited: <?php echo date('M Y', strtotime($prov['accreditation_date'])); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $prov['provider_type']; ?></span>
                                    </td>
                                    <td>
                                        <?php if($prov['primary_contact']): ?>
                                            <strong><?php echo htmlspecialchars($prov['primary_contact']); ?></strong><br>
                                            <small class="text-muted">
                                                <i class="fas fa-phone"></i> <?php echo htmlspecialchars($prov['primary_contact_number']); ?><br>
                                                <?php if($prov['contact_email']): ?>
                                                    <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($prov['contact_email']); ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">No primary contact set</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-secondary"><?php echo $planCount; ?> plan(s)</span><br>
                                        <small class="text-muted">
                                            <?php 
                                            $categories = [];
                                            foreach($providerPlans as $plan) {
                                                if(!in_array($plan['plan_category'], $categories)) {
                                                    $categories[] = $plan['plan_category'];
                                                }
                                            }
                                            echo implode(', ', $categories);
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick='viewProviderDetails(<?php echo json_encode($prov); ?>)'>
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Plans List -->
            <div class="col-12 mb-4">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Available Plans with Annual Limits</h3>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="showLimits">
                            <label class="form-check-label" for="showLimits">Show Annual Limits</label>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table" id="plansTable">
                            <thead>
                                <tr>
                                    <th>Plan Name</th>
                                    <th>Provider</th>
                                    <th>Category</th>
                                    <th>Annual Limit</th>
                                    <th>Premium</th>
                                    <th>Documents</th>
                                    <th>Contact Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allPlans as $plan): 
                                    $docCount = $planDocCounts[$plan['id']] ?? 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($plan['plan_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($plan['plan_code'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($plan['provider_name']); ?><br>
                                        <small class="text-muted"><?php echo $plan['provider_type']; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $plan['plan_category']; ?> plan-badge">
                                            <?php echo $plan['plan_category']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="limit-info">
                                            <strong>₱<?php echo number_format($plan['annual_limit'], 2); ?></strong><br>
                                            <small class="text-muted">
                                                Out: ₱<?php echo number_format($plan['outpatient_limit'], 2); ?> | 
                                                ER: ₱<?php echo number_format($plan['emergency_limit'], 2); ?>
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        ₱<?php echo number_format($plan['total_premium'], 2); ?><br>
                                        <small class="text-muted">
                                            Emp: ₱<?php echo number_format($plan['employee_share'], 2); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if($docCount > 0): ?>
                                            <span class="badge bg-success document-badge" onclick="viewPlanDocuments(<?php echo $plan['id']; ?>)">
                                                <i class="fas fa-file"></i> <?php echo $docCount; ?> doc(s)
                                            </span>
                                        <?php endif; ?>
                                        <?php if($plan['plan_contract_file']): ?>
                                            <br><small class="text-muted">
                                                <a href="#" class="text-decoration-none" onclick="viewDocument('<?php echo $plan['plan_contract_file']; ?>')">
                                                    <i class="fas fa-file-contract"></i> Contract
                                                </a>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($plan['plan_contact']): ?>
                                            <small><i class="fas fa-user"></i> <?php echo htmlspecialchars($plan['plan_contact']); ?></small><br>
                                        <?php endif; ?>
                                        <?php if($plan['plan_email']): ?>
                                            <small><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($plan['plan_email']); ?></small><br>
                                        <?php endif; ?>
                                        <?php if($plan['plan_portal_url']): ?>
                                            <a href="<?php echo htmlspecialchars($plan['plan_portal_url']); ?>" target="_blank" class="text-decoration-none">
                                                <small><i class="fas fa-external-link-alt"></i> Portal</small>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Enrollment List -->
            <div class="col-12">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Employee Enrollments</h3>
                        <span class="badge bg-primary">Total: <?php echo $totalEnrolled; ?> enrollments</span>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Plan</th>
                                    <th>Provider</th>
                                    <th>Coverage Period</th>
                                    <th>Annual Limit</th>
                                    <th>Dependents</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrollments as $enr): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($enr['employee_name']); ?></strong><br>
                                        <small class="text-muted">ID: <?php echo htmlspecialchars($enr['emp_code']); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($enr['plan_name']); ?>
                                        <span class="badge bg-<?php echo $enr['plan_category']; ?> ms-1 plan-badge">
                                            <?php echo $enr['plan_category']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($enr['provider_name']); ?></td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($enr['effective_date'])); ?> <br>
                                        <small class="text-muted">to <?php echo date('M d, Y', strtotime($enr['card_expiry_date'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge limit-badge">
                                            ₱<?php echo number_format($enr['annual_limit'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($enr['dependent_count'] > 0): ?>
                                            <span class="badge bg-info">+<?php echo $enr['dependent_count']; ?> dependent(s)</span>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($enr['status'] == 'Active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php elseif($enr['status'] == 'Pending'): ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?php echo $enr['status']; ?></span>
                                        <?php endif; ?>
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

    <!-- Modal: Provider Details & Plans -->
    <div class="modal fade" id="providerModal" tabindex="-1">
        <div class="modal-dialog modal-xl-custom">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="providerModalName"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                   <div class="row">
                       <div class="col-md-4 border-end">
                           <h6 class="text-uppercase text-muted small fw-bold mb-3">Contact Information</h6>
                           <div id="provContactDetails"></div>
                           
                           <h6 class="text-uppercase text-muted small fw-bold mt-4 mb-3">Quick Links</h6>
                           <div id="provLinks" class="d-grid gap-2"></div>
                       </div>
                       <div class="col-md-8">
                           <h6 class="text-uppercase text-muted small fw-bold mb-3">Available Plans with Annual Limits</h6>
                           <div class="table-responsive">
                               <table class="table table-bordered table-sm w-100">
                                   <thead class="table-light">
                                       <tr>
                                           <th>Plan</th>
                                           <th>Category</th>
                                           <th>Annual Limit</th>
                                           <th>Premium</th>
                                           <th>Documents/Contact</th>
                                       </tr>
                                   </thead>
                                   <tbody id="provPlansBody"></tbody>
                               </table>
                           </div>
                           
                           <div class="mt-4">
                               <h6 class="text-uppercase text-muted small fw-bold mb-3">Provider Documents</h6>
                               <div id="provDocuments" class="d-flex flex-wrap gap-2"></div>
                           </div>
                       </div>
                   </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" onclick="openAddPlan(currentProviderId)">
                        <i class="fas fa-plus"></i> Add New Plan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Add New Plan -->
    <div class="modal fade" id="addPlanModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="add_plan" value="1">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-folder-plus"></i> Add New Plan</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                        <div class="row g-3">
                            <!-- Basic Information -->
                            <div class="col-md-6">
                                <label class="form-label">Select Provider *</label>
                                <select name="provider_id" id="addPlanProviderSelect" class="form-select" required onchange="filterPlansByProvider('addPlan', this.value)">
                                    <option value="">Choose a Provider...</option>
                                    <?php foreach($providers as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['provider_name']); ?> (<?php echo $p['provider_type']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Plan Category *</label>
                                <select name="plan_category" class="form-select" required onchange="updateLimitsByCategory(this.value)">
                                    <option value="Medical">Medical</option>
                                    <option value="Dental">Dental</option>
                                    <option value="Vision">Vision</option>
                                    <option value="Life">Life Insurance</option>
                                    <option value="Maternity">Maternity</option>
                                    <option value="Disability">Disability</option>
                                    <option value="Comprehensive">Comprehensive</option>
                                </select>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Plan Name *</label>
                                <input type="text" name="plan_name" class="form-control" placeholder="e.g. Gold Health Plus, Dental Care Premium" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Plan Code</label>
                                <input type="text" name="plan_code" class="form-control" placeholder="e.g. GHP-2024, DCP-001">
                            </div>

                            <!-- Annual Limits Section -->
                            <div class="col-12 mt-3">
                                <h6 class="border-bottom pb-2">Annual Coverage Limits (PHP)</h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Annual Limit</label>
                                <input type="number" name="annual_limit" id="annual_limit" class="form-control" value="100000" step="5000" required>
                                <small class="form-text text-muted">Total coverage per year</small>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Outpatient Limit</label>
                                <input type="number" name="outpatient_limit" id="outpatient_limit" class="form-control" value="20000" step="1000">
                                <small class="form-text text-muted">For consultations, lab tests</small>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Emergency Limit</label>
                                <input type="number" name="emergency_limit" id="emergency_limit" class="form-control" value="50000" step="1000">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Room Accommodation</label>
                                <input type="number" name="room_limit" class="form-control" value="3000" step="500">
                                <small class="form-text text-muted">Daily room rate limit</small>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Professional Fees</label>
                                <input type="number" name="professional_fees_limit" class="form-control" value="50000" step="1000">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Per Illness Limit</label>
                                <input type="number" name="per_illness_limit" class="form-control" value="50000" step="1000">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Surgical Limit</label>
                                <input type="number" name="surgical_limit" class="form-control" value="40000" step="1000">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Maternity Coverage</label>
                                <input type="number" name="maternity_coverage" class="form-control" value="50000" step="1000">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Dental Coverage</label>
                                <input type="number" name="dental_coverage" class="form-control" value="10000" step="500">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Optical Coverage</label>
                                <input type="number" name="optical_coverage" class="form-control" value="5000" step="500">
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Preventive Care</label>
                                <input type="number" name="preventive_care_limit" class="form-control" value="5000" step="500">
                            </div>

                            <!-- Coverage Details -->
                            <div class="col-md-4">
                                <label class="form-label">Room Type</label>
                                <select name="room_type" class="form-select">
                                    <option value="Ward">Ward</option>
                                    <option value="Semi-Private">Semi-Private</option>
                                    <option value="Private">Private</option>
                                    <option value="Suite">Suite</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Coverage Days/Year</label>
                                <input type="number" name="coverage_days" class="form-control" value="45">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Waiting Period (days)</label>
                                <input type="number" name="waiting_period" class="form-control" value="30">
                            </div>

                            <!-- Premium Information -->
                            <div class="col-12 mt-3">
                                <h6 class="border-bottom pb-2">Premium Information</h6>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Total Monthly Premium *</label>
                                <input type="number" name="total_premium" class="form-control" value="2500" step="100" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Employer Share</label>
                                <input type="number" name="employer_share" class="form-control" value="2000" step="100">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Employee Share</label>
                                <input type="number" name="employee_share" class="form-control" value="500" step="100">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Additional Dependent Premium</label>
                                <input type="number" name="dependent_premium" class="form-control" value="1500" step="100">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Payment Frequency</label>
                                <select name="frequency" class="form-select">
                                    <option value="Monthly">Monthly</option>
                                    <option value="Quarterly">Quarterly</option>
                                    <option value="Semi-Annual">Semi-Annual</option>
                                    <option value="Annual">Annual</option>
                                </select>
                            </div>

                            <!-- Contact & Documents -->
                            <div class="col-12 mt-3">
                                <h6 class="border-bottom pb-2">Plan Contact & Documents</h6>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Plan Contact Person</label>
                                <input type="text" name="plan_contact" class="form-control" placeholder="Account Manager / Hotline">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Plan Contact Email</label>
                                <input type="email" name="plan_email" class="form-control" placeholder="plan@provider.com">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Plan Website</label>
                                <input type="url" name="plan_website" class="form-control" placeholder="https://...">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Client Portal URL</label>
                                <input type="url" name="plan_portal" class="form-control" placeholder="https://portal.provider.com">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Contract Document</label>
                                <input type="file" name="contract_file" class="form-control" accept=".pdf,.doc,.docx">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Plan Brochure</label>
                                <input type="file" name="brochure_file" class="form-control" accept=".pdf,.jpg,.png">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Terms & Conditions</label>
                                <input type="file" name="terms_file" class="form-control" accept=".pdf,.doc,.docx">
                            </div>

                            <!-- Additional Information -->
                            <div class="col-md-6">
                                <label class="form-label">Inclusions</label>
                                <textarea name="inclusions" class="form-control" rows="3" placeholder="List of covered services..."></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Exclusions</label>
                                <textarea name="exclusions" class="form-control" rows="3" placeholder="List of excluded services..."></textarea>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"></textarea>
                            </div>

                            <!-- Dates -->
                            <div class="col-md-4">
                                <label class="form-label">Effective Date</label>
                                <input type="date" name="effective_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Expiry Date</label>
                                <input type="date" name="expiry_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Renewal Date</label>
                                <input type="date" name="renewal_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+11 months')); ?>">
                            </div>

                            <!-- Existing Plans Display -->
                            <div class="col-12">
                                <div class="card bg-light border-0 mt-3">
                                    <div class="card-body py-2">
                                        <small class="text-muted fw-bold">EXISTING PLANS FOR SELECTED PROVIDER:</small>
                                        <ul id="existingPlansList" class="list-inline mb-0 mt-1 small text-dark">
                                            <li class="list-inline-item text-muted">No provider selected.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save New Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Enroll Employee -->
    <div class="modal fade" id="enrollModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="enroll_employee" value="1">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Enroll Employee to HMO Plan</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Employee *</label>
                                <select name="employee_id" id="employeeSelect" class="form-select" required>
                                    <option value="">Select Employee...</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" data-card-number="<?php echo htmlspecialchars($emp['latest_card'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($emp['name']); ?> (ID: <?php echo htmlspecialchars($emp['employee_id']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Enrollment Date *</label>
                                <input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Filter by Provider</label>
                                <select id="enrollProviderSelect" class="form-select" onchange="filterPlansByProvider('enroll', this.value)">
                                    <option value="">All Providers</option>
                                    <?php foreach($providers as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['provider_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">Select Plan *</label>
                                <select name="plan_id" id="enrollPlanSelect" class="form-select" required onchange="showPlanDetails(this.value)">
                                    <option value="">Select a Plan...</option>
                                    <?php foreach($allPlans as $plan): ?>
                                        <option value="<?php echo $plan['id']; ?>" data-limit="<?php echo $plan['annual_limit']; ?>" data-premium="<?php echo $plan['employee_share']; ?>">
                                            [<?php echo $plan['provider_name']; ?>] <?php echo $plan['plan_name']; ?> - ₱<?php echo number_format($plan['annual_limit'], 2); ?> limit
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="planDetails" class="mt-3 p-3 bg-light border rounded d-none">
                                    <h6 class="text-primary mb-2"><i class="fas fa-info-circle"></i> Plan & Provider Details</h6>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <small class="d-block text-muted">Financials:</small>
                                            <span class="badge bg-success">Limit: <span id="planAnnualLimit"></span></span>
                                            <span class="badge bg-warning text-dark">Share: <span id="planEmployeeShare"></span>/mo</span>
                                        </div>
                                        <div class="col-md-6 border-start ps-3">
                                            <small class="d-block text-muted">Provider Contact:</small>
                                            <div id="providerContactInfo" class="small"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Card Number (Optional)</label>
                                <input type="text" name="card_number" id="card_number" class="form-control" placeholder="HMO Card Number">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Effective Date</label>
                                <input type="date" name="effective_date" class="form-control">
                                <small class="form-text text-muted">Leave blank for default (enrollment date + waiting period)</small>
                            </div>

                            <!-- Dependents Section -->
                            <div class="col-12">
                                <h6 class="border-bottom pb-2">Add Dependents (Optional)</h6>
                                <div id="dependentsContainer">
                                    <div class="dependent-row row g-2 mb-2">
                                        <div class="col-md-4">
                                            <input type="text" name="dependent_name[]" class="form-control form-control-sm" placeholder="Dependent Name">
                                        </div>
                                        <div class="col-md-3">
                                            <select name="dependent_relationship[]" class="form-select form-select-sm">
                                                <option value="Spouse">Spouse</option>
                                                <option value="Child" selected>Child</option>
                                                <option value="Parent">Parent</option>
                                                <option value="Sibling">Sibling</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="date" name="dependent_birthdate[]" class="form-control form-control-sm" placeholder="Birthdate">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeDependent(this)"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addDependent()">
                                    <i class="fas fa-plus"></i> Add Another Dependent
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Enroll Employee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Add Provider (Simplified) -->
    <div class="modal fade" id="addProviderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Add New Provider</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Provider management module is under development. For now, please contact system administrator to add new providers.
                    </div>
                    <p>Future features will include:</p>
                    <ul>
                        <li>Provider registration with multiple contacts</li>
                        <li>Document upload for accreditation</li>
                        <li>Provider portal integration</li>
                        <li>Performance tracking</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        // Initialize DataTables
        $(document).ready(function() {
            $('#providersTable').DataTable({
                pageLength: 10,
                responsive: true
            });
            
            $('#plansTable').DataTable({
                pageLength: 10,
                responsive: true
            });

            // Auto-fill Card Number on Employee Select
            $('#employeeSelect').on('change', function() {
                var selectedCard = $(this).find(':selected').data('card-number');
                if(selectedCard) {
                    $('#card_number').val(selectedCard);
                } else {
                    $('#card_number').val('');
                }
            });
        });
        
        // Global Data
        const allPlans = <?php echo $plansJson; ?>;
        var currentProviderId = null;
        
        // Plan Category Default Limits
        const categoryLimits = {
            'Medical': { annual: 100000, outpatient: 20000, emergency: 50000 },
            'Dental': { annual: 15000, outpatient: 15000, emergency: 5000 },
            'Vision': { annual: 8000, outpatient: 8000, emergency: 2000 },
            'Life': { annual: 500000, outpatient: 0, emergency: 0 },
            'Maternity': { annual: 50000, outpatient: 50000, emergency: 10000 },
            'Disability': { annual: 200000, outpatient: 0, emergency: 0 },
            'Comprehensive': { annual: 250000, outpatient: 50000, emergency: 75000 }
        };
        
        function updateLimitsByCategory(category) {
            const limits = categoryLimits[category] || categoryLimits['Medical'];
            document.getElementById('annual_limit').value = limits.annual;
            document.getElementById('outpatient_limit').value = limits.outpatient;
            document.getElementById('emergency_limit').value = limits.emergency;
        }
        
        function filterPlansByProvider(context, providerId) {
            if (context === 'enroll') {
                const planSelect = document.getElementById('enrollPlanSelect');
                const allOptions = planSelect.querySelectorAll('option');
                
                allOptions.forEach(opt => {
                    if(opt.value === '') return;
                    const plan = allPlans.find(p => p.id == opt.value);
                    if(!providerId || (plan && plan.provider_id == providerId)) {
                        opt.style.display = '';
                    } else {
                        opt.style.display = 'none';
                    }
                });
                
                planSelect.value = '';
                document.getElementById('planDetails').classList.add('d-none');

            } else if (context === 'addPlan') {
                const list = document.getElementById('existingPlansList');
                list.innerHTML = '';
                
                if(!providerId) {
                    list.innerHTML = '<li class="list-inline-item text-muted">No provider selected.</li>';
                    return;
                }

                const filtered = allPlans.filter(p => p.provider_id == providerId);
                if (filtered.length > 0) {
                    filtered.forEach(p => {
                        let badgeClass = 'bg-light text-dark border';
                        if(p.plan_category === 'Medical') badgeClass = 'bg-primary text-white';
                        else if(p.plan_category === 'Dental') badgeClass = 'bg-success text-white';
                        else if(p.plan_category === 'Vision') badgeClass = 'bg-info text-white';
                        else if(p.plan_category === 'Life') badgeClass = 'bg-danger text-white';

                        list.innerHTML += `<li class="list-inline-item badge ${badgeClass} me-1 mb-1">
                            ${p.plan_name} (₱${Number(p.annual_limit).toLocaleString()})
                        </li>`;
                    });
                } else {
                    list.innerHTML = '<li class="list-inline-item text-muted">No existing plans yet for this provider.</li>';
                }
            }
        }

        function showPlanDetails(planId) {
            const plan = allPlans.find(p => p.id == planId);
            if(plan) {
                document.getElementById('planAnnualLimit').textContent = '₱' + Number(plan.annual_limit).toLocaleString();
                document.getElementById('planEmployeeShare').textContent = '₱' + Number(plan.employee_share).toLocaleString();
                
                // Provider Contact Info
                let contactInfo = '';
                if(plan.emergency_hotline) contactInfo += `<div><i class="fas fa-phone-alt text-danger"></i> <span class="fw-bold">${plan.emergency_hotline}</span> (Emergency)</div>`;
                if(plan.client_services_email) contactInfo += `<div><i class="fas fa-envelope text-primary"></i> ${plan.client_services_email}</div>`;
                if(plan.portal_url) contactInfo += `<div><a href="${plan.portal_url}" target="_blank" class="text-decoration-none"><i class="fas fa-external-link-alt"></i> Client Portal</a></div>`;
                
                if(!contactInfo) contactInfo = '<em class="text-muted">No direct contact info available.</em>';
                
                document.getElementById('providerContactInfo').innerHTML = contactInfo;
                document.getElementById('planDetails').classList.remove('d-none');
            }
        }

        function viewProviderDetails(prov) {
            currentProviderId = prov.id;
            document.getElementById('providerModalName').innerText = prov.provider_name + ' - ' + prov.provider_type;
            
            // Contact Details
            let contactHtml = `
                <p><i class="fas fa-user-circle"></i> <strong>${prov.contact_person || 'N/A'}</strong></p>
                <p><i class="fas fa-envelope"></i> ${prov.contact_email || 'N/A'}</p>
                <p><i class="fas fa-phone"></i> ${prov.contact_number || 'N/A'}</p>
            `;
            
            if(prov.client_services_email) {
                contactHtml += `<p><i class="fas fa-headset"></i> Client Services: ${prov.client_services_email}</p>`;
            }
            
            if(prov.emergency_hotline) {
                contactHtml += `<p><i class="fas fa-ambulance"></i> Emergency: ${prov.emergency_hotline}</p>`;
            }
            
            if(prov.address) {
                contactHtml += `<p><i class="fas fa-map-marker-alt"></i> ${prov.address}</p>`;
            }
            
            if(prov.coverage_areas) {
                contactHtml += `<p><i class="fas fa-map"></i> Coverage: ${prov.coverage_areas}</p>`;
            }
            
            document.getElementById('provContactDetails').innerHTML = contactHtml;
            
            // Links
            let linksHtml = '';
            if(prov.website) {
                linksHtml += `<a href="${prov.website}" target="_blank" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-globe"></i> Website
                </a>`;
            }
            
            if(prov.portal_url) {
                linksHtml += `<a href="${prov.portal_url}" target="_blank" class="btn btn-outline-success btn-sm">
                    <i class="fas fa-user-lock"></i> Client Portal
                </a>`;
            }
            
            if(prov.claims_email) {
                linksHtml += `<a href="mailto:${prov.claims_email}" class="btn btn-outline-warning btn-sm">
                    <i class="fas fa-file-invoice-dollar"></i> Submit Claims
                </a>`;
            }
            
            document.getElementById('provLinks').innerHTML = linksHtml;
            
            // Plans Table
            const tbody = document.getElementById('provPlansBody');
            tbody.innerHTML = '';
            
            const provPlans = allPlans.filter(p => p.provider_id == prov.id);
            if (provPlans.length > 0) {
                provPlans.forEach(p => {
                    let docs = '';
                    if(p.plan_website) docs += `<a href="${p.plan_website}" class="text-decoration-none" target="_blank"><i class="fas fa-link"></i> Website</a><br>`;
                    if(p.plan_portal_url) docs += `<a href="${p.plan_portal_url}" class="text-decoration-none" target="_blank"><i class="fas fa-external-link-alt"></i> Portal</a><br>`;
                    if(p.plan_contact) docs += `<span class="small text-muted"><i class="fas fa-user"></i> ${p.plan_contact}</span><br>`;
                    if(p.plan_email) docs += `<span class="small text-muted"><i class="fas fa-envelope"></i> ${p.plan_email}</span>`;

                    tbody.innerHTML += `
                        <tr>
                            <td>
                                <strong>${p.plan_name}</strong><br>
                                <small class="text-muted">${p.description || ''}</small>
                            </td>
                            <td><span class="badge bg-${p.plan_category}">${p.plan_category}</span></td>
                            <td>
                                <strong>₱${Number(p.annual_limit).toLocaleString()}</strong><br>
                                <small class="text-muted">
                                    OP: ₱${Number(p.outpatient_limit).toLocaleString()}<br>
                                    ER: ₱${Number(p.emergency_limit).toLocaleString()}
                                </small>
                            </td>
                            <td>
                                ₱${Number(p.total_premium).toLocaleString()}/mo<br>
                                <small class="text-muted">Emp: ₱${Number(p.employee_share).toLocaleString()}</small>
                            </td>
                            <td>${docs}</td>
                        </tr>
                    `;
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No plans found.</td></tr>';
            }
            
            // Documents Section
            document.getElementById('provDocuments').innerHTML = `
                <span class="badge bg-secondary">Documents module coming soon</span>
            `;

            const modal = new bootstrap.Modal(document.getElementById('providerModal'));
            modal.show();
        }

        function openAddPlan(providerId) {
            const provModalEl = document.getElementById('providerModal');
            const provModal = bootstrap.Modal.getInstance(provModalEl);
            if(provModal) provModal.hide();

            const addModal = new bootstrap.Modal(document.getElementById('addPlanModal'));
            addModal.show();

            if(providerId) {
                const select = document.getElementById('addPlanProviderSelect');
                select.value = providerId;
                filterPlansByProvider('addPlan', providerId);
            }
        }
        
        // Dependent Management
        let dependentCount = 1;
        
        function addDependent() {
            dependentCount++;
            const container = document.getElementById('dependentsContainer');
            const newRow = document.createElement('div');
            newRow.className = 'dependent-row row g-2 mb-2';
            newRow.innerHTML = `
                <div class="col-md-4">
                    <input type="text" name="dependent_name[]" class="form-control form-control-sm" placeholder="Dependent Name">
                </div>
                <div class="col-md-3">
                    <select name="dependent_relationship[]" class="form-select form-select-sm">
                        <option value="Spouse">Spouse</option>
                        <option value="Child" selected>Child</option>
                        <option value="Parent">Parent</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="date" name="dependent_birthdate[]" class="form-control form-control-sm" placeholder="Birthdate">
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeDependent(this)"><i class="fas fa-times"></i></button>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function removeDependent(button) {
            if(document.querySelectorAll('.dependent-row').length > 1) {
                button.closest('.dependent-row').remove();
            }
        }
        
        function viewPlanDocuments(planId) {
            alert('Documents view for Plan ID: ' + planId + ' will be implemented in the next version.');
        }
        
        function viewDocument(filename) {
            alert('Document: ' + filename + '\n\nIn a real system, this would open or download the document.');
        }
    </script>
</body>
</html>