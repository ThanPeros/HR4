<?php
// compensation/salary-structure.php
include '../config/db.php';
include '../includes/sidebar.php';

// Initialize theme
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}
$currentTheme = $_SESSION['theme'];

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

// --- DOLE & SALARY CONFIGURATION --- //
// Constants based on Philippine Department of Labor and Employment (DOLE) - NCR Rates (Approximation)
define('DOLE_MIN_DAILY_WAGE_NCR', 645.00); // Updated reference for 2024-2025 optimization
define('WORK_DAYS_FACTOR', 261); // Standard corporate 5-day work week factor

function calculateDailyRate($monthly) {
    return ($monthly * 12) / WORK_DAYS_FACTOR;
}

function calculateHourlyRate($daily) {
    return $daily / 8;
}

// --- DB INITIALIZATION (Optimized) --- //
try {
    // Check if table exists to avoid overhead
    $checkTable = $conn->query("SHOW TABLES LIKE 'salary_grades'");
    if ($checkTable->num_rows == 0) {
        $sql = "CREATE TABLE salary_grades (
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
        )";
        $conn->query($sql);
        
        // Seed Optimized Data (DOLE Compliant - Above Minimum Wage)
        // Minimum Monthly = 645 * 261 / 12 = ~14,028.75.
        // We set SG-1 Entry at 16,000 to be safe and competitive.
        $seedSql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description) VALUES 
        ('SG-1', 'Entry Level / Clerk', 16000.00, 19000.00, 22000.00, 5, 'Entry level positions. Fully compliant with NCR Wage Order.'),
        ('SG-2', 'Junior Associate', 19000.00, 23000.00, 28000.00, 5, 'Junior roles with 1-2 years experience.'),
        ('SG-3', 'Associate / Specialist', 24000.00, 30000.00, 36000.00, 5, 'Specialized roles requiring technical proficiency.'),
        ('SG-4', 'Senior Associate', 32000.00, 40000.00, 48000.00, 5, 'Senior roles with mentorship responsibilities.'),
        ('SG-5', 'Team Lead / Supervisor', 42000.00, 52000.00, 62000.00, 5, 'Project or team supervision.'),
        ('SG-6', 'Assistant Manager', 55000.00, 68000.00, 80000.00, 5, 'Departmental assistance and strategy.'),
        ('SG-7', 'Manager', 70000.00, 90000.00, 110000.00, 5, 'Full department management.'),
        ('SG-8', 'Senior Manager / Director', 100000.00, 130000.00, 160000.00, 5, 'Executive leadership.')";
        $conn->query($seedSql);
    }
} catch (Exception $e) {
    // Silent fail on existence check
}

// --- HANDLE SUBMISSIONS --- //
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_grade') {
        $id = $_POST['grade_id'] ?? '';
        $level = $conn->real_escape_string($_POST['grade_level']);
        $name = $conn->real_escape_string($_POST['grade_name']);
        $min = floatval($_POST['min_salary']);
        $mid = floatval($_POST['mid_salary']);
        $max = floatval($_POST['max_salary']);
        $steps = intval($_POST['step_count']);
        $desc = $conn->real_escape_string($_POST['description']);
        $status = $conn->real_escape_string($_POST['status']);

        // Validation against Minimum Wage
        $daily = calculateDailyRate($min);
        if ($daily < DOLE_MIN_DAILY_WAGE_NCR) {
            $message = "Warning: The minimum salary provided results in a daily rate below the NCR Minimum Wage (₱" . DOLE_MIN_DAILY_WAGE_NCR . ").";
            $message_type = "error";
        } else {
            if (!empty($id)) {
                // Update
                $sql = "UPDATE salary_grades SET 
                        grade_name='$name', min_salary=$min, mid_salary=$mid, max_salary=$max, 
                        step_count=$steps, description='$desc', status='$status' 
                        WHERE id='$id'";
                if ($conn->query($sql)) {
                    $message = "Salary Grade updated successfully.";
                    $message_type = "success";
                }
            } else {
                // Insert
                $sql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description, status) 
                        VALUES ('$level', '$name', $min, $mid, $max, $steps, '$desc', '$status')";
                if ($conn->query($sql)) {
                    $message = "Salary Grade added successfully.";
                    $message_type = "success";
                } else {
                    $message = "Error: " . $conn->error;
                    $message_type = "error";
                }
            }
        }
    }
}

// Fetch Data
$grades = $conn->query("SELECT * FROM salary_grades ORDER BY CAST(SUBSTRING(grade_level, 4) AS UNSIGNED) ASC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Structure | HR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Consistent CSS Style -->
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
            line-height: 1.4;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            margin-top: 60px;
        }
        
        body.dark-mode .main-content { background-color: var(--dark-bg); }

        .page-header {
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            background: white;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        body.dark-mode .page-header { background: var(--dark-card); }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-subtitle { margin: 0; font-size: 0.9rem; color: #6c757d; }
        
        .report-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            border-top: 4px solid var(--primary-color);
        }
        
        body.dark-mode .report-card { background: var(--dark-card); }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f8f9fc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #4e73df;
            border-bottom: 2px solid #e3e6f0;
        }
        
        body.dark-mode .data-table th { background: #2d3748; color: #63b3ed; border-bottom-color: #4a5568; }
        
        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid #e3e6f0;
            vertical-align: middle;
        }
        
        body.dark-mode .data-table td { border-bottom-color: #4a5568; }

        .badge-dole {
            background: #e3f2fd;
            color: #0d47a1;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .currency {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        .dole-info-box {
            background: #e8f4f8;
            border-left: 4px solid #36b9cc;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
         body.dark-mode .dole-info-box {
            background: #2c3e50;
            border-left-color: #36b9cc;
            color: #e8f4f8;
         }
         
         /* Modal Dark Mode */
         body.dark-mode .modal-content { background: var(--dark-card); color: white; }
         body.dark-mode .modal-header { border-bottom-color: #4a5568; }
         body.dark-mode .modal-footer { border-top-color: #4a5568; }
         body.dark-mode .form-control { background: #2d3748; border-color: #4a5568; color: white; }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

<div class="main-content">
    <div class="content-area">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">
                    <i class="fas fa-money-bill-wave"></i>
                    Salary Structure Optimization
                </h1>
                <p class="page-subtitle">Based on Philippines DOLE Standards (NCR Wage Orders)</p>
            </div>
            <div>
                <button class="btn btn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i> Add Salary Grade
                </button>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo ($message_type == 'success') ? 'success' : 'danger'; ?> alert-dismissible fade show">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- DOLE Information -->
        <div class="dole-info-box">
            <div class="d-flex align-items-center mb-2">
                <i class="fas fa-info-circle fa-lg me-2 text-info"></i>
                <strong>Compliance Reference: DOLE NCR Minimum Wage</strong>
            </div>
            <p class="mb-0">
                Current estimation based on Daily Minimum Wage of <strong>₱<?php echo number_format(DOLE_MIN_DAILY_WAGE_NCR, 2); ?></strong>. 
                Monthly Equivalent (Factor <?php echo WORK_DAYS_FACTOR; ?>): <strong>₱<?php echo number_format(calculateDailyRate(DOLE_MIN_DAILY_WAGE_NCR / 12 * WORK_DAYS_FACTOR), 2); ?></strong> (Approx). 
                <br><em>Ensure all Entry Level (SG-1) grades meet or exceed this statutory requirement.</em>
            </p>
        </div>

        <!-- Grade List -->
        <div class="report-card">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Description</th>
                            <th>Monthly Range (Min - Max)</th>
                            <th>Daily Rate (Est.)</th>
                            <th>Hourly Rate (Est.)</th>
                            <th>Steps</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($grades->num_rows > 0): ?>
                            <?php while($row = $grades->fetch_assoc()): ?>
                                <?php 
                                    $daily = calculateDailyRate($row['min_salary']);
                                    $hourly = calculateHourlyRate($daily);
                                    $isCompliant = $daily >= DOLE_MIN_DAILY_WAGE_NCR;
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700; font-size: 1.1em; color: var(--primary-color);">
                                            <?php echo htmlspecialchars($row['grade_level']); ?>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($row['grade_name']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                                    <td>
                                        <span class="currency text-success">₱<?php echo number_format($row['min_salary'], 2); ?></span> 
                                        <span class="text-muted mx-1">-</span>
                                        <span class="currency">₱<?php echo number_format($row['max_salary'], 2); ?></span>
                                    </td>
                                    <td>
                                        <div class="currency">₱<?php echo number_format($daily, 2); ?></div>
                                        <?php if(!$isCompliant): ?>
                                            <span class="badge bg-danger">Below Min</span>
                                        <?php else: ?>
                                            <span class="badge-dole"><i class="fas fa-check"></i> Compliant</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="currency">₱<?php echo number_format($hourly, 2); ?></td>
                                    <td><?php echo $row['step_count']; ?></td>
                                    <td>
                                        <span class="badge <?php echo $row['status']=='Active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $row['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" onclick='editGrade(<?php echo json_encode($row); ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center p-4">No salary grades defined.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Add Salary Grade</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_grade">
                    <input type="hidden" name="grade_id" id="grade_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Grade Level</label>
                            <input type="text" class="form-control" name="grade_level" id="grade_level" placeholder="e.g. SG-1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Grade Name</label>
                            <input type="text" class="form-control" name="grade_name" id="grade_name" placeholder="e.g. Entry Level" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Minimum Salary (Monthly)</label>
                            <input type="number" step="0.01" class="form-control" name="min_salary" id="min_salary" required oninput="calculateRates()">
                            <small class="text-muted" id="calc_daily">Daily: ₱0.00</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Midpoint</label>
                            <input type="number" step="0.01" class="form-control" name="mid_salary" id="mid_salary" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Maximum Salary</label>
                            <input type="number" step="0.01" class="form-control" name="max_salary" id="max_salary" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Step Count</label>
                            <input type="number" class="form-control" name="step_count" id="step_count" value="5">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                    </div>
                    
                    <div class="alert alert-info py-2" style="font-size: 0.85rem;">
                        <i class="fas fa-info-circle"></i> Rates are calculated based on DOLE Factor <strong>261</strong> days/year.
                        Minimum NCR Daily Wage compliance check: <strong>₱<?php echo DOLE_MIN_DAILY_WAGE_NCR; ?></strong>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Grade</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const modalInstance = new bootstrap.Modal(document.getElementById('gradeModal'));
    const WORK_FACTOR = 261;

    function openModal() {
        document.getElementById('modalTitle').innerText = 'Add Salary Grade';
        document.getElementById('grade_id').value = '';
        document.getElementById('grade_level').value = '';
        document.getElementById('grade_name').value = '';
        document.getElementById('min_salary').value = '';
        document.getElementById('mid_salary').value = '';
        document.getElementById('max_salary').value = '';
        document.getElementById('description').value = '';
        document.getElementById('calc_daily').innerText = 'Daily: ₱0.00';
        modalInstance.show();
    }

    function editGrade(data) {
        document.getElementById('modalTitle').innerText = 'Edit Salary Grade';
        document.getElementById('grade_id').value = data.id;
        document.getElementById('grade_level').value = data.grade_level;
        document.getElementById('grade_name').value = data.grade_name;
        document.getElementById('min_salary').value = data.min_salary;
        document.getElementById('mid_salary').value = data.mid_salary;
        document.getElementById('max_salary').value = data.max_salary;
        document.getElementById('step_count').value = data.step_count;
        document.getElementById('status').value = data.status;
        document.getElementById('description').value = data.description;
        
        calculateRates(); // Update preview
        modalInstance.show();
    }

    function calculateRates() {
        const min = parseFloat(document.getElementById('min_salary').value) || 0;
        const daily = (min * 12) / WORK_FACTOR;
        const calcEl = document.getElementById('calc_daily');
        calcEl.innerText = 'Daily: ₱' + daily.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        if (daily < <?php echo DOLE_MIN_DAILY_WAGE_NCR; ?>) {
            calcEl.classList.add('text-danger');
            calcEl.classList.remove('text-muted');
            calcEl.innerText += ' (Below NCR Min)';
        } else {
            calcEl.classList.remove('text-danger');
            calcEl.classList.add('text-muted');
        }
    }
</script>

</body>
</html>