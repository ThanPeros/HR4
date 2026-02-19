<?php
// core-human/print-report.php
session_start();

// Reliable Database Connection
require_once '../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    global $conn;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    // Fallback connection if included file didn't expose $conn
    $conn = new mysqli('localhost', 'root', '', 'dummy_hr4');
    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }
}

// Get filter parameters (same as report.php)
$department_filter = isset($_GET['department']) ? $conn->real_escape_string($_GET['department']) : '';
$status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
$employment_type_filter = isset($_GET['employment_type']) ? $conn->real_escape_string($_GET['employment_type']) : '';
$search_term = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Build WHERE clause
$where_conditions = [];
$query_params = [];
$types = '';

if (!empty($department_filter)) {
    $where_conditions[] = "department = ?";
    $query_params[] = $department_filter;
    $types .= 's';
}
if (!empty($status_filter)) {
    $where_conditions[] = "status = ?";
    $query_params[] = $status_filter;
    $types .= 's';
}
if (!empty($employment_type_filter)) {
    $where_conditions[] = "contract = ?";
    $query_params[] = $employment_type_filter;
    $types .= 's';
}
if (!empty($search_term)) {
    $where_conditions[] = "(name LIKE ? OR job_title LIKE ? OR department LIKE ?)";
    $term = "%$search_term%";
    $query_params[] = $term;
    $query_params[] = $term;
    $query_params[] = $term;
    $types .= 'sss';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Fetch employees
$employees_sql = "SELECT * FROM employees $where_clause ORDER BY department, name";
$stmt = $conn->prepare($employees_sql);

// Bind params if any
if (!empty($query_params)) {
    $stmt->bind_param($types, ...$query_params);
}

$stmt->execute();
$result = $stmt->get_result();
$employees = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Core Human Capital Summary - Print</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 12px; /* 10-12pt equivalent */
            color: #000;
            background: #fff;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #e74a3b;
            padding-bottom: 10px;
        }
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .report-title {
            font-size: 18px;
            font-weight: bold;
            margin-top: 10px;
        }
        .meta-info {
            font-size: 11px;
            color: #555;
            margin-top: 5px;
            font-style: italic;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        
        /* Badges for print */
        .employment-badge {
            padding: 2px 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-weight: bold;
            font-size: 11px;
            display: inline-block;
        }
        .badge-full-time { background: #d1ecf1; } /* Regular */
        .badge-probation { background: #f8d7da; } /* Probationary */
        .badge-contract { background: #d4edda; } /* Contract */
        .badge-part-time { background: #fff3cd; } /* Project-Based */
        
        /* Remove screen elements */
        .no-print { display: none; }
        
        @media print {
            @page { margin: 10mm; }
            body { margin: 0; padding: 0; }
        }
    </style>
    <script>
        window.onload = function() {
            // Small delay to ensure styles are loaded
            setTimeout(function() {
                window.print();
            }, 500);
            
            // Close the window after printing (or cancelling)
            // Note: onafterprint support varies, but works in most modern browsers
            window.onafterprint = function() {
                // window.close(); 
            };
        }
    </script>
</head>
<body>
    <div class="header">
        <div class="company-name">Slate Freight</div>
        <div class="report-title">Core Human Capital Summary</div>
        <div class="meta-info">Generated: <?php echo date('F d, Y g:i A'); ?></div>
    </div>

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
            <?php if (count($employees) > 0): ?>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($emp['employee_id'] ?? $emp['id']); ?></td>
                        <td><b><?php echo htmlspecialchars($emp['name']); ?></b></td>
                        <td><?php echo htmlspecialchars($emp['department']); ?></td>
                        <td><?php echo htmlspecialchars($emp['job_title']); ?></td>
                        <td>
                            <?php
                            $c = $emp['contract'] ?? 'N/A';
                            $cls = 'badge-secondary';
                            if ($c === 'Regular') $cls = 'badge-full-time';
                            elseif ($c === 'Probationary') $cls = 'badge-probation';
                            elseif ($c === 'Contract') $cls = 'badge-contract';
                            elseif ($c === 'Project-Based') $cls = 'badge-part-time';
                            echo '<span class="employment-badge ' . $cls . '">' . htmlspecialchars($c) . '</span>';
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($emp['status']); ?></td>
                        <td><?php echo number_format((float)($emp['salary'] ?? 0), 2); ?></td>
                        <td><?php echo !empty($emp['date_hired']) ? date('M d, Y', strtotime($emp['date_hired'])) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" style="text-align:center">No records found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
