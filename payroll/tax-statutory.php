<?php
// payroll/tax-statutory.php - Tax & Statutory Deduction Management
session_start();

// Include database configuration
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// Initialize auto-responsive system
require_once '../responsive/responsive.php';

// Check database connection
if (!isset($pdo)) {
    die("Database connection failed. Please checks config/db.php");
}

// Initialize theme
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$currentTheme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

// ============ CREATE TABLES IF NOT EXISTS ============
function createStatutoryTables($pdo) {
    // SSS Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS sss_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        min_salary DECIMAL(10,2) NOT NULL,
        max_salary DECIMAL(10,2) NOT NULL,
        ee_share DECIMAL(10,2) NOT NULL, /* Employee Share */
        er_share DECIMAL(10,2) NOT NULL, /* Employer Share */
        ec_share DECIMAL(10,2) NOT NULL, /* EC Contribution */
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // PhilHealth Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS philhealth_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        min_salary DECIMAL(10,2) NOT NULL,
        max_salary DECIMAL(10,2) NOT NULL,
        rate DECIMAL(5,2) NOT NULL, /* Percentage */
        fixed_amount DECIMAL(10,2) DEFAULT 0,
        type ENUM('Percentage', 'Fixed') DEFAULT 'Percentage',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Pag-IBIG Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagibig_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        min_salary DECIMAL(10,2) NOT NULL,
        max_salary DECIMAL(10,2) NOT NULL,
        ee_rate DECIMAL(5,2) NOT NULL,
        er_rate DECIMAL(5,2) NOT NULL,
        max_fund_salary DECIMAL(10,2) DEFAULT 5000,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Withholding Tax Table (Annual/Monthly)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tax_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bracket_name VARCHAR(50),
        min_income DECIMAL(12,2) NOT NULL,
        max_income DECIMAL(12,2) NOT NULL,
        base_tax DECIMAL(10,2) DEFAULT 0,
        excess_rate DECIMAL(5,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

createStatutoryTables($pdo);

// ============ HANDLE REQUESTS ============
$message = '';
$msgType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Generic Add/Update Logic (Simplified for demonstration)
    if (isset($_POST['add_sss'])) {
        try {
            $stmt = $pdo->prepare("INSERT INTO sss_table (min_salary, max_salary, ee_share, er_share, ec_share) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['min'], $_POST['max'], $_POST['ee'], $_POST['er'], $_POST['ec']]);
            $message = "SSS Bracket added successfully!";
            $msgType = "success";
        } catch (PDOException $e) { $message = $e->getMessage(); $msgType = "error"; }
    }
}

// Fetch Data
$sss_data = $pdo->query("SELECT * FROM sss_table ORDER BY min_salary ASC")->fetchAll();
$ph_data = $pdo->query("SELECT * FROM philhealth_table ORDER BY min_salary ASC")->fetchAll();
$pi_data = $pdo->query("SELECT * FROM pagibig_table ORDER BY min_salary ASC")->fetchAll();
$tax_data = $pdo->query("SELECT * FROM tax_table ORDER BY min_income ASC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax & Statutory Management | HR System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Theme Variables - Matching payroll-calculation.php */
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

        /* Base Styles */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            transition: background 0.3s, color 0.3s;
            line-height: 1.4;
        }
        body.dark-mode { background-color: var(--dark-bg); color: var(--text-light); }

        /* Main Content */
        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            margin-top: 60px;
            margin-left: 250px; /* Sidebar width adjustment */
            transition: margin-left 0.3s ease;
        }
        @media (max-width: 768px) { .main-content { margin-left: 0; padding: 1rem; } }
        body.dark-mode .main-content { background-color: var(--dark-bg); }

        /* Page Header */
        .page-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
            margin-bottom: 2rem;
            border-radius: var(--border-radius);
        }
        body.dark-mode .page-header { background: #2d3748; border-bottom: 1px solid #4a5568; }
        .page-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--text-dark); }
        body.dark-mode .page-title { color: var(--text-light); }
        .page-subtitle { color: #6c757d; font-size: 0.9rem; }
        body.dark-mode .page-subtitle { color: #a0aec0; }

        /* Cards/Containers */
        .content-area {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 1.5rem;
            padding: 1.5rem;
        }
        body.dark-mode .content-area { background: var(--dark-card); }

        /* Tabs */
        .nav-tabs {
            display: flex;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 1.5rem;
            gap: 5px;
            flex-wrap: wrap;
        }
        .nav-item { margin-bottom: -1px; }
        .nav-link {
            display: block;
            padding: 0.75rem 1.5rem;
            border: 1px solid transparent;
            border-top-left-radius: var(--border-radius);
            border-top-right-radius: var(--border-radius);
            color: var(--primary-color);
            text-decoration: none;
            cursor: pointer;
            background: rgba(78, 115, 223, 0.05);
            font-weight: 600;
        }
        .nav-link:hover { border-color: #e9ecef #e9ecef #dee2e6; }
        .nav-link.active {
            color: #495057;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
            border-top: 3px solid var(--primary-color);
        }
        body.dark-mode .nav-tabs { border-color: #4a5568; }
        body.dark-mode .nav-link { color: #a0aec0; background: rgba(255,255,255,0.05); }
        body.dark-mode .nav-link.active {
            color: #fff;
            background-color: var(--dark-card);
            border-color: #4a5568 #4a5568 var(--dark-card);
        }

        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* Tables - Matching Budget Table */
        .data-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .data-table th {
            background: #f8f9fc; padding: 0.75rem; text-align: left;
            font-weight: 600; border-bottom: 1px solid #e3e6f0; color: var(--primary-color);
        }
        body.dark-mode .data-table th { background: #2d3748; border-bottom: 1px solid #4a5568; color: var(--text-light); }
        .data-table td { padding: 0.75rem; border-bottom: 1px solid #e3e6f0; }
        body.dark-mode .data-table td { border-bottom: 1px solid #4a5568; }
        .data-table tr:last-child td { border-bottom: none; }

        /* Buttons */
        .btn {
            padding: 0.5rem 1rem; border: none; border-radius: var(--border-radius);
            cursor: pointer; font-weight: 600; transition: all 0.3s;
            display: inline-flex; align-items: center; gap: 0.5rem; color: white;
            font-size: 0.9rem;
        }
        .btn-primary { background: var(--primary-color); }
        .btn-primary:hover { background: #2e59d9; }
        .btn-success { background: var(--success-color); }
        .btn-success:hover { background: #17a673; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.8rem; }
        .btn-disabled { background: #6c757d; cursor: not-allowed; opacity: 0.7; }

        /* Forms */
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; }
        .form-control {
            width: 100%; padding: 0.75rem; border: 1px solid #e3e6f0;
            border-radius: var(--border-radius); font-size: 1rem;
        }
        body.dark-mode .form-control { background: #2d3748; border-color: #4a5568; color: white; }
        .form-control:focus { outline: none; border-color: var(--primary-color); }

        /* Alerts */
        .alert {
            padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1.5rem;
            border-left: 4px solid;
        }
        .alert-success { background: #d4edda; border-color: var(--success-color); color: #155724; }
        body.dark-mode .alert-success { background: #22543d; color: #9ae6b4; }
        .alert-error { background: #f8d7da; border-color: var(--danger-color); color: #721c24; }
        body.dark-mode .alert-error { background: #744210; color: #fbd38d; }

        /* Calculator Specific */
        .calculator-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
        .result-box {
            background: #2c3e50; color: white; padding: 1.5rem;
            border-radius: var(--border-radius); box-shadow: var(--shadow);
        }
        .result-row {
            display: flex; justify-content: space-between; margin-bottom: 0.75rem;
            border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 0.5rem;
        }
        .result-total { font-weight: 700; color: var(--success-color); font-size: 1.1rem; }
        
        /* Modals (Basic Overlay) */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content {
            background-color: #fefefe; margin: 10% auto; padding: 20px;
            border: 1px solid #888; width: 50%; border-radius: var(--border-radius);
            position: relative;
        }
        body.dark-mode .modal-content { background-color: var(--dark-card); border-color: #4a5568; }
        .close { float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: var(--danger-color); }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

<div class="main-content">
    
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-landmark"></i> Tax & Statutory Management
        </h1>
        <p class="page-subtitle">Manage contributions, rates, and deduction rules for SSS, PhilHealth, Pag-IBIG, and Tax.</p>
    </div>

    <?php if(!empty($message)): ?>
        <div class="alert alert-<?php echo $msgType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <ul class="nav-tabs">
        <li class="nav-item">
            <a class="nav-link active" onclick="openTab('sss', this)">SSS Table</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="openTab('philhealth', this)">PhilHealth</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="openTab('pagibig', this)">Pag-IBIG</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="openTab('tax', this)">Withholding Tax</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="openTab('calculator', this)"> <i class="fas fa-calculator"></i> Deduction Simulator</a>
        </li>
    </ul>

    <!-- SSS Tab -->
    <div id="sss" class="tab-content active">
        <div class="content-area">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                <h2 style="font-size:1.2rem;">Social Security System (SSS)</h2>
                <button class="btn btn-primary" onclick="showModal('add-sss-modal')"><i class="fas fa-plus"></i> Add Bracket</button>
            </div>
            
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Range Compensation</th>
                            <th>Employee Share</th>
                            <th>Employer Share</th>
                            <th>EC Contribution</th>
                            <th>Total Contribution</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($sss_data) > 0): ?>
                            <?php foreach($sss_data as $row): ?>
                            <tr>
                                <td>₱<?php echo number_format($row['min_salary'], 2); ?> - ₱<?php echo number_format($row['max_salary'], 2); ?></td>
                                <td>₱<?php echo number_format($row['ee_share'], 2); ?></td>
                                <td>₱<?php echo number_format($row['er_share'], 2); ?></td>
                                <td>₱<?php echo number_format($row['ec_share'], 2); ?></td>
                                <td><b>₱<?php echo number_format($row['ee_share'] + $row['er_share'] + $row['ec_share'], 2); ?></b></td>
                                <td>
                                    <button class="btn btn-sm btn-primary"><i class="fas fa-edit"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <!-- MOCK DATA FOR DEMO -->
                            <tr>
                                <td>₱4,250.00 - ₱4,749.99</td>
                                <td>₱202.50</td>
                                <td>₱405.00</td>
                                <td>₱10.00</td>
                                <td><b>₱617.50</b></td>
                                <td><button class="btn btn-sm btn-disabled"><i class="fas fa-edit"></i></button></td>
                            </tr>
                            <tr>
                                <td>₱20,000.00 - ₱20,499.99</td>
                                <td>₱900.00</td>
                                <td>₱1,800.00</td>
                                <td>₱30.00</td>
                                <td><b>₱2,730.00</b></td>
                                <td><button class="btn btn-sm btn-disabled"><i class="fas fa-edit"></i></button></td>
                            </tr>
                            <tr>
                                <td>₱29,750.00 and above</td>
                                <td>₱1,350.00</td>
                                <td>₱2,700.00</td>
                                <td>₱30.00</td>
                                <td><b>₱4,080.00</b></td>
                                <td><button class="btn btn-sm btn-disabled"><i class="fas fa-edit"></i></button></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PhilHealth Tab -->
    <div id="philhealth" class="tab-content">
        <div class="content-area">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                <h2 style="font-size:1.2rem;">PhilHealth Contribution</h2>
                <button class="btn btn-primary"><i class="fas fa-sync"></i> Update Rates</button>
            </div>
            <div class="alert alert-success">
                <strong><i class="fas fa-info-circle"></i> Current Rule:</strong> 4% ~ 5% Rate (Shared 50/50) for income floors/ceilings.
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Basic Monthly Salary</th>
                            <th>Premium Rate</th>
                            <th>Monthly Premium</th>
                            <th>Employee Share (50%)</th>
                            <th>Employer Share (50%)</th>
                        </tr>
                    </thead>
                    <tbody>
                         <tr>
                            <td>₱10,000.00 and below</td>
                            <td>Fixed</td>
                            <td>₱400.00</td>
                            <td>₱200.00</td>
                            <td>₱200.00</td>
                        </tr>
                         <tr>
                            <td>₱10,000.01 to ₱89,999.99</td>
                            <td>4.0%</td>
                            <td>Variable</td>
                            <td>2.0%</td>
                            <td>2.0%</td>
                        </tr>
                         <tr>
                            <td>₱90,000.00 and above</td>
                            <td>Fixed</td>
                            <td>₱3,600.00</td>
                            <td>₱1,800.00</td>
                            <td>₱1,800.00</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Pag-IBIG Tab -->
    <div id="pagibig" class="tab-content">
        <div class="content-area">
             <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                <h2 style="font-size:1.2rem;">Pag-IBIG Fund</h2>
                 <button class="btn btn-primary"><i class="fas fa-sync"></i> Update Rates</button>
            </div>
             <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Monthly Compensation</th>
                            <th>Employee Rate</th>
                            <th>Employer Rate</th>
                            <th>Max Salary Base</th>
                        </tr>
                    </thead>
                    <tbody>
                         <tr>
                            <td>₱1,500.00 and below</td>
                            <td>1%</td>
                            <td>2%</td>
                            <td>-</td>
                        </tr>
                         <tr>
                            <td>Over ₱1,500.00</td>
                            <td>2%</td>
                            <td>2%</td>
                            <td>₱5,000.00</td>
                        </tr>
                    </tbody>
                </table>
                <p style="margin-top:15px; color:var(--text-dark); opacity:0.7; font-size:0.9rem;">
                    * Maximum contribution is usually capped at ₱100.00 (Employee) and ₱100.00 (Employer) based on the ₱5,000.00 max salary base.
                </p>
            </div>
        </div>
    </div>

    <!-- Tax Tab -->
    <div id="tax" class="tab-content">
        <div class="content-area">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                <h2 style="font-size:1.2rem;">Withholding Tax Table (TRAIN Law)</h2>
                <button class="btn btn-primary"><i class="fas fa-plus"></i> Add Bracket</button>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Annual Income Range</th>
                            <th>Basic Tax</th>
                            <th>Excess Rate (+)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>₱250,000 and below</td>
                            <td>₱0.00</td>
                            <td>0%</td>
                        </tr>
                         <tr>
                            <td>₱250,000 - ₱400,000</td>
                            <td>₱0.00</td>
                            <td>20% of excess over ₱250k</td>
                        </tr>
                         <tr>
                            <td>₱400,000 - ₱800,000</td>
                            <td>₱30,000.00</td>
                            <td>25% of excess over ₱400k</td>
                        </tr>
                         <tr>
                            <td>₱800,000 - ₱2,000,000</td>
                            <td>₱130,000.00</td>
                            <td>30% of excess over ₱800k</td>
                        </tr>
                         <tr>
                            <td>₱2,000,000 - ₱8,000,000</td>
                            <td>₱490,000.00</td>
                            <td>32% of excess over ₱2M</td>
                        </tr>
                         <tr>
                            <td>Above ₱8,000,000</td>
                            <td>₱2,410,000.00</td>
                            <td>35% of excess over ₱8M</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Calculator Tab -->
    <div id="calculator" class="tab-content">
        <div class="calculator-grid">
            <div class="content-area">
                <h2 style="font-size:1.2rem; margin-bottom:1rem;">Quick Calculator</h2>
                <div class="form-group">
                    <label class="form-label">Monthly Basic Salary</label>
                    <input type="number" id="calc_salary" class="form-control" placeholder="e.g. 25000" onkeyup="calculateDetails()">
                </div>
                <button class="btn btn-success" onclick="calculateDetails()"><i class="fas fa-calculator"></i> Compute Deductions</button>
            </div>

            <div class="content-area">
                <h2 style="font-size:1.2rem; margin-bottom:1rem;">Computation Result</h2>
                <div id="calc_result">
                    <p style="color:var(--text-dark); opacity:0.6;">Enter salary to see breakdown...</p>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Auto-Responsive and Logic Scripts -->
<script>
    function openTab(tabId, btn) {
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.nav-link').forEach(b => b.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        btn.classList.add('active');
    }

    // Modal Simulation
    function showModal(id) {
        // Simple prompt for now as full modals require more HTML
        alert("This feature allows adding new brackets to the database.");
    }

    // Client-side calculator for immediate feedback
    function calculateDetails() {
        const salary = parseFloat(document.getElementById('calc_salary').value) || 0;
        
        let sss = 0;
        let philhealth = 0;
        let pagibig = 100; // Capped usually
        
        // SSS Estimate (Rough Logic)
        if(salary < 4250) sss = 202.50;
        else if(salary > 29750) sss = 1350;
        else sss = Math.ceil(salary / 500) * 22.5; // Approximation

        // PhilHealth (4% / 2)
        let phBase = salary;
        if(phBase < 10000) phBase = 10000;
        if(phBase > 90000) phBase = 90000;
        philhealth = (phBase * 0.04) / 2;

        // Pag-IBIG
        if(salary > 5000) pagibig = 100;
        else pagibig = salary * 0.02; // 2%

        // Tax (Simplified for Monthly)
        // Taxable = Salary - (SSS + PH + PI)
        const taxable = salary - (sss + philhealth + pagibig);
        let tax = 0;
        
        // Monthly Tax Table (Simplified 2023)
        if(taxable > 20833) {
            if(taxable < 33333) tax = (taxable - 20833) * 0.20;
            else if(taxable < 66667) tax = 2500 + (taxable - 33333) * 0.25;
            else if(taxable < 166667) tax = 10833 + (taxable - 66667) * 0.30;
            else if(taxable < 666667) tax = 40833.33 + (taxable - 166667) * 0.32;
            else tax = 200833.33 + (taxable - 666667) * 0.35;
        }

        const totalDeduction = sss + philhealth + pagibig + tax;
        const netPay = salary - totalDeduction;

        const html = `
            <div class="result-box">
                <div class="result-row"><span>Gross Salary:</span> <b>M${salary.toFixed(2)}</b></div>
                <div class="result-row"><span>SSS Contribution:</span> <span>₱${sss.toFixed(2)}</span></div>
                <div class="result-row"><span>PhilHealth:</span> <span>₱${philhealth.toFixed(2)}</span></div>
                <div class="result-row"><span>Pag-IBIG:</span> <span>₱${pagibig.toFixed(2)}</span></div>
                <div class="result-row"><span>Withholding Tax:</span> <span>₱${tax.toFixed(2)}</span></div>
                <div class="result-row result-total"><span>Total Deductions:</span> <span>₱${totalDeduction.toFixed(2)}</span></div>
                <div style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid rgba(255,255,255,0.2);">
                    <div style="font-size:1.5rem; color:#1cc88a; font-weight:bold;">Net Pay: ₱${netPay.toFixed(2)}</div>
                </div>
            </div>
        `;
        
        document.getElementById('calc_result').innerHTML = html;
    }
</script>

</body>
</html>
