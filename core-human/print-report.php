<?php
// print-report.php

// Required to get access to local DB
require_once '../config/db.php';

// Fetches employees directly from local database
function fetchEmployeesFromDB($filters = []) {
    global $pdo;

    if (!isset($pdo) || !$pdo) {
        return ['error' => 'Database connection failed: DB connection not initialized. Check config/db.php'];
    }

    try {
        $sql = "SELECT * FROM employees WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['department'])) {
            $sql .= " AND department = ?";
            $params[] = $filters['department'];
        }
        if (!empty($filters['employment_type'])) {
            $sql .= " AND contract = ?";
            $params[] = $filters['employment_type'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (name LIKE ? OR job_title LIKE ? OR department LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filtered_employees = [];
        
        foreach ($data as $emp) {
            // Apply any default formatting
            if (empty($emp['status'])) $emp['status'] = 'Active';
            if (!isset($emp['job_title'])) $emp['job_title'] = '';
            if (!isset($emp['contract'])) $emp['contract'] = '';
            
            $emp['salary'] = !empty($emp['salary']) ? $emp['salary'] : (!empty($emp['basic_pay']) ? $emp['basic_pay'] : 0);
            
            if (!empty($emp['hire_date']) && $emp['hire_date'] !== '0000-00-00') {
                $emp['date_hired'] = $emp['hire_date'];
            } elseif (!empty($emp['date_hired']) && $emp['date_hired'] !== '0000-00-00') {
                $emp['hire_date'] = $emp['date_hired'];
            } else {
                $emp['hire_date']  = '';
                $emp['date_hired'] = '';
            }

            $filtered_employees[] = $emp;
        }

        return $filtered_employees;

    } catch (Throwable $e) {
        return ['error' => 'Database connection failed: ' . $e->getMessage()];
    }
}

$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$employment_type_filter = isset($_GET['employment_type']) ? $_GET['employment_type'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

$api_filters = [];
if (!empty($department_filter)) $api_filters['department'] = $department_filter;
if (!empty($status_filter)) $api_filters['status'] = $status_filter;
if (!empty($employment_type_filter)) $api_filters['employment_type'] = $employment_type_filter;
if (!empty($search_term)) $api_filters['search'] = $search_term;

$api_response = fetchEmployeesFromDB($api_filters);

$filtered_employees = [];
if (isset($api_response['error'])) {
    $api_error = $api_response['error'];
} else {
    // DB fetch returns directly an array of employees
    $filtered_employees = $api_response;
}

function getFilterDescription($filters)
{
    $descriptions = [];
    if (!empty($filters['department'])) $descriptions[] = "Department: " . htmlspecialchars($filters['department']);
    if (!empty($filters['status'])) $descriptions[] = "Status: " . htmlspecialchars($filters['status']);
    if (!empty($filters['employment_type'])) $descriptions[] = "Employment Type: " . htmlspecialchars($filters['employment_type']);
    if (!empty($filters['search'])) $descriptions[] = "Search: " . htmlspecialchars($filters['search']);
    return empty($descriptions) ? 'All Employees' : implode(', ', $descriptions);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Output</title>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 24pt;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 10px;
            border-bottom: 2px solid #e74a3b;
            display: inline-block;
            padding-bottom: 5px;
        }
        .report-title {
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .meta-info {
            font-size: 10pt;
            color: #555;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
        }
        th, td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            -webkit-print-color-adjust: exact;
        }
        .text-right {
            text-align: right;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
        
        /* Utility styles for printing */
        table { page-break-inside:auto }
        tr    { page-break-inside:avoid; page-break-after:auto }
        thead { display:table-header-group }
        tfoot { display:table-footer-group }
		
		/* Additional print button class */
		.print-btn {
			padding: 10px 20px;
			background-color: #007bff;
			color: white;
			border: none;
			border-radius: 5px;
			cursor: pointer;
			margin-bottom: 20px;
			font-size: 16px;
		}
		
		.print-btn:hover {
			background-color: #0056b3;
		}
    </style>
</head>
<body>

    <button onclick="window.print()" class="print-btn no-print">Print Report</button>

    <div class="header">
        <div class="company-name">Slate Freight</div>
        <div class="report-title">Core Human Capital Report</div>
        <div class="meta-info">
            Generated: <?php echo date('F j, Y g:i A'); ?><br>
            Filters: <?php echo getFilterDescription($_GET); ?>
        </div>
    </div>

    <?php if (isset($api_error)): ?>
        <p style="color: red;">Error loading data: <?php echo htmlspecialchars($api_error); ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Contract Type</th>
                    <th>Status</th>
                    <th>Salary</th>
                    <th>Hire Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($filtered_employees) > 0): ?>
                    <?php foreach ($filtered_employees as $employee): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($employee['id'] ?? ''); ?></td>
                            <td><strong><?php echo htmlspecialchars($employee['name'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($employee['department'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($employee['job_title'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($employee['contract'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($employee['status'] ?: 'N/A'); ?></td>
                            <td class="text-right">
                                <?php
                                $salary = isset($employee['salary']) ? floatval($employee['salary']) : 0;
                                echo '₱' . number_format($salary, 2);
                                ?>
                            </td>
                            <td>
                                <?php
                                $hire_date = $employee['date_hired'] ?? $employee['hire_date'] ?? '';
                                if (!empty($hire_date)) {
                                    try {
                                        echo date('M j, Y', strtotime($hire_date));
                                    } catch (Exception $e) {
                                        echo htmlspecialchars($hire_date);
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">No employees found matching the given filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <script>
        // Automatically trigger print dialog when the page loads
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
