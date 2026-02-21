<?php
// Start output buffering at the VERY beginning
ob_start();
include '../includes/sidebar.php';

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle - do this before any HTML output
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');

    // Clear output buffer before redirect
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

$currentTheme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

include '../responsive/responsive.php';

// Function to fetch employee data from API
function fetchEmployeesFromAPI($filters = []) {
    // Determine base API URL dynamically if on a domain, otherwise default to localhost
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $api_url = "$protocol://$host/HR1/api/employee_data.php?t=" . time(); 
    
    // If it's a domain with a subdirectory structure like yours, it might be safer to use this or keep localhost if internal
    // But since you encountered 404, we'll try to be more robust.
    if ($host === 'localhost') {
        $api_url = "http://localhost/HR1/api/employee_data.php?t=" . time();
    }
    
    // Add filters to API URL if needed
    if (!empty($filters)) {
        $query_params = [];
        if (!empty($filters['department'])) {
            $query_params[] = "department=" . urlencode($filters['department']);
        }
        if (!empty($filters['status'])) {
            $query_params[] = "status=" . urlencode($filters['status']);
        }
        if (!empty($filters['employment_type'])) {
            $query_params[] = "contract=" . urlencode($filters['employment_type']);
        }
        if (!empty($filters['search'])) {
            $query_params[] = "search=" . urlencode($filters['search']);
        }
        
        if (!empty($query_params)) {
            $api_url .= "&" . implode('&', $query_params);
        }
    }
    
    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Set to true in production with valid SSL
    
    // Execute cURL session
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Check for cURL errors
    if (curl_error($ch)) {
        error_log("cURL Error: " . curl_error($ch));
        curl_close($ch);
        return ['error' => 'Failed to connect to API: ' . curl_error($ch)];
    }
    
    curl_close($ch);
    
    // Check HTTP status code
    if ($httpCode !== 200) {
        return ['error' => "API returned HTTP code: $httpCode"];
    }
    
    // Decode JSON response
    $data = json_decode($response, true);
    
    // Check if JSON decoding was successful
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Failed to parse API response: ' . json_last_error_msg()];
    }
    
    return $data;
}

// Get filter parameters with proper validation
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$employment_type_filter = isset($_GET['employment_type']) ? $_GET['employment_type'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

// Build filters array for API
$api_filters = [];
if (!empty($department_filter)) $api_filters['department'] = $department_filter;
if (!empty($status_filter)) $api_filters['status'] = $status_filter;
if (!empty($employment_type_filter)) $api_filters['employment_type'] = $employment_type_filter;
if (!empty($search_term)) $api_filters['search'] = $search_term;

// Fetch employees from API
$api_response = fetchEmployeesFromAPI($api_filters);

// Process API response
$filtered_employees = [];
$departments = [];
$contract_types = [];
$overall_stats = [
    'total_employees' => 0,
    'active_employees' => 0,
    'overall_avg_salary' => 0,
    'total_payroll' => 0
];

if (isset($api_response['error'])) {
    $api_error = $api_response['error'];
    error_log("API Error: " . $api_error);
} else {
    // Assuming the API returns an array of employees
    // Adjust this based on your actual API response structure
    if (isset($api_response['data'])) {
        $filtered_employees = $api_response['data'];
    } elseif (is_array($api_response)) {
        $filtered_employees = $api_response;
    }
    
    // Extract unique departments and contract types for filters
    $dept_set = [];
    $contract_set = [];
    $active_count = 0;
    $total_salary = 0;
    
    foreach ($filtered_employees as &$emp) {
        // Ensure standard field names
        if (isset($emp['basic_pay']) && !isset($emp['salary'])) $emp['salary'] = $emp['basic_pay'];
        if (isset($emp['date_hired']) && !isset($emp['hire_date'])) $emp['hire_date'] = $emp['date_hired'];
        if (isset($emp['hire_date']) && !isset($emp['date_hired'])) $emp['date_hired'] = $emp['hire_date'];

        // Collect departments
        if (!empty($emp['department'])) {
            $dept_set[$emp['department']] = true;
        }
        
        // Collect contract types
        if (!empty($emp['contract'])) {
            $contract_set[$emp['contract']] = true;
        }
        
        // Count active employees
        if (isset($emp['status']) && $emp['status'] === 'Active') {
            $active_count++;
        }
        
        // Sum salaries
        if (isset($emp['salary'])) {
            $total_salary += floatval($emp['salary']);
        }
    }
    
    $departments = array_keys($dept_set);
    sort($departments);
    
    $contract_types = array_keys($contract_set);
    sort($contract_types);
    
    // Calculate statistics
    $overall_stats = [
        'total_employees' => count($filtered_employees),
        'active_employees' => $active_count,
        'overall_avg_salary' => count($filtered_employees) > 0 ? $total_salary / count($filtered_employees) : 0,
        'total_payroll' => $total_salary
    ];
}

// Handle Export Requests - must be processed before HTML
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $filters = $_GET;

    // Only generate export for non-PDF types
    if ($export_type !== 'pdf') {
        generateExport($export_type, $filters, $filtered_employees);
        exit;
    }
}

// Export Function (for Excel and CSV only)
function generateExport($type, $filters, $employees)
{
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    if ($type === 'excel') {
        exportToExcel($employees, $filters);
    } elseif ($type === 'csv') {
        exportToCSV($employees, $filters);
    }
}

function exportToExcel($employees, $filters)
{
    // Clear output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="employee_report_' . date('Y-m-d') . '.xls"');

    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .header { background-color: #4e73df; color: white; padding: 10px; }
        .company-name { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
    </style>";
    echo "</head>";
    echo "<body>";

    echo "<div class='header'>";
    echo "<div class='company-name'>Slate Freight</div>";
    echo "<h2>Core Human Capital Report</h2>";
    echo "<p>Generated: " . date('F j, Y g:i A') . "</p>";
    echo "<p>Filters: " . getFilterDescription($filters) . "</p>";
    echo "</div>";

    echo "<table>";
    echo "<tr>
        <th>ID</th>
        <th>Name</th>
        <th>Department</th>
        <th>Position</th>
        <th>Employment Type</th>
        <th>Status</th>
        <th>Salary</th>
        <th>Hire Date</th>
        <th>Gender</th>
    </tr>";

    foreach ($employees as $employee) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($employee['id'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($employee['name'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($employee['department'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($employee['job_title'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($employee['contract'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($employee['status'] ?? '') . "</td>";
        echo "<td>₱" . number_format($employee['salary'] ?? 0, 2) . "</td>";
        echo "<td>" . htmlspecialchars($employee['date_hired'] ?? $employee['hire_date'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($employee['gender'] ?? '') . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    echo "</body></html>";
    exit;
}

function exportToCSV($employees, $filters)
{
    // Clear output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employee_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Header row
    fputcsv($output, [
        'Employee ID',
        'Full Name',
        'Department',
        'Job Title',
        'Employment Type',
        'Status',
        'Salary',
        'Hire Date',
        'Gender'
    ]);

    // Data rows
    foreach ($employees as $employee) {
        fputcsv($output, [
            $employee['id'] ?? '',
            $employee['name'] ?? '',
            $employee['department'] ?? '',
            $employee['job_title'] ?? '',
            $employee['contract'] ?? '',
            $employee['status'] ?? '',
            '₱' . number_format($employee['salary'] ?? 0, 2),
            $employee['date_hired'] ?? $employee['hire_date'] ?? '',
            $employee['gender'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

function getFilterDescription($filters)
{
    $descriptions = [];

    if (!empty($filters['department'])) {
        $descriptions[] = "Department: " . $filters['department'];
    }

    if (!empty($filters['status'])) {
        $descriptions[] = "Status: " . $filters['status'];
    }

    if (!empty($filters['employment_type'])) {
        $descriptions[] = "Employment Type: " . $filters['employment_type'];
    }

    if (!empty($filters['search'])) {
        $descriptions[] = "Search: " . $filters['search'];
    }

    return empty($descriptions) ? 'All Employees' : implode(', ', $descriptions);
}

// Include sidebar after all processing is done

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Reports | HR System</title>
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
        
        /* Print Header Style (Hidden by default) */
        .print-header-layout {
            display: none;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        /* API Error Banner */
        .api-error-banner {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 1rem;
            margin: 1.5rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        body.dark-mode .api-error-banner {
            background: #742a2a;
            border-color: #a06464;
            color: #feb2b2;
        }

        .api-error-banner i {
            font-size: 1.5rem;
        }

        /* Enhanced Filter Styles */
        .filters-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
            transition: all 0.3s;
        }

        body.dark-mode .filters-container {
            background: var(--dark-card);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e3e6f0;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s;
        }

        body.dark-mode .form-input,
        body.dark-mode .form-select {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

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

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Enhanced Report Cards */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 0 1.5rem 1.5rem;
        }

        .report-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border-left: 4px solid var(--primary-color);
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }

        body.dark-mode .report-card {
            background: var(--dark-card);
        }

        .report-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .report-card-header {
            border-bottom: 1px solid #4a5568;
        }

        .report-card-title {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .report-card-actions {
            display: flex;
            gap: 0.5rem;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            border-radius: var(--border-radius);
            background: #f8f9fc;
            transition: all 0.3s;
        }

        .stat-item:hover {
            background: #e9ecef;
            transform: scale(1.02);
        }

        body.dark-mode .stat-item {
            background: #2d3748;
        }

        body.dark-mode .stat-item:hover {
            background: #4a5568;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        body.dark-mode .stat-label {
            color: #a0aec0;
        }

        /* Enhanced Table Styles */
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
            position: sticky;
            top: 0;
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

        .data-table tr {
            transition: all 0.3s;
        }

        .data-table tr:hover {
            background: #f8f9fc;
            transform: scale(1.01);
        }

        body.dark-mode .data-table tr:hover {
            background: #2d3748;
        }

        /* Status badges */
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
            background: #742a2a;
            color: #feb2b2;
        }

        /* Print-specific styles for PDF simulation */
        @media print {

            /* Hide non-essential elements */
            .theme-toggle-container,
            .filters-container,
            .reports-grid,
            .report-card-actions,
            .btn,
            .page-header,
            .page-subtitle,
            .api-error-banner {
                display: none !important;
            }

            /* Reset body styling */
            body {
                background: white !important;
                color: black !important;
                font-size: 12pt !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Main content styling */
            .main-content {
                padding: 0 !important;
                margin: 0 !important;
                background: white !important;
                width: 100% !important;
            }

            .content-area {
                box-shadow: none !important;
                border-radius: 0 !important;
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Page title for print */
            .page-title {
                text-align: center;
                font-size: 18pt !important;
                margin-bottom: 20px !important;
                padding-bottom: 10px !important;
                border-bottom: 2px solid #000 !important;
                color: black !important;
            }

            /* Report info section */
            .report-info {
                margin-bottom: 20px !important;
                padding: 10px !important;
                border: 1px solid #ddd !important;
                background: #f9f9f9 !important;
                font-size: 10pt !important;
            }

            /* Table styling for print */
            .data-table {
                width: 100% !important;
                border-collapse: collapse !important;
                font-size: 10pt !important;
                margin-top: 20px !important;
            }

            .data-table th {
                background: #f0f0f0 !important;
                color: black !important;
                border: 1px solid #000 !important;
                padding: 6px !important;
                font-weight: bold !important;
            }

            .data-table td {
                border: 1px solid #000 !important;
                padding: 5px !important;
                text-align: left !important;
            }

            .data-table tr {
                page-break-inside: avoid !important;
            }

            /* Remove hover effects for print */
            .data-table tr:hover {
                background: transparent !important;
                transform: none !important;
            }

            /* Status badges for print */
            .status-badge {
                padding: 2px 6px !important;
                border: 1px solid #000 !important;
                background: white !important;
                color: black !important;
            }

            .employment-badge {
                padding: 2px 6px !important;
                border: 1px solid #000 !important;
                background: white !important;
                color: black !important;
            }

            /* Remove currency styling for print */
            .currency {
                font-family: inherit !important;
            }

            /* Footer for print */
            .print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 8pt !important;
                padding: 10px !important;
                border-top: 1px solid #000 !important;
                background: white !important;
                color: black !important;
            }

            /* Page breaks */
            .page-break {
                page-break-before: always !important;
                margin-top: 20px !important;
            }

            /* Hide in print but show in screen */
            .no-print {
                display: none !important;
            }

            .print-only {
                display: block !important;
            }
        }

        /* Screen-only elements */
        .no-print {
            display: block;
        }

        .print-only {
            display: none;
        }

        .employment-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-full-time {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-part-time {
            background: #fff3cd;
            color: #856404;
        }

        .badge-contract {
            background: #d4edda;
            color: #155724;
        }

        .badge-probation {
            background: #f8d7da;
            color: #721c24;
        }

        /* Currency formatting */
        .currency {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        /* Theme Toggle */
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

        /* Main Content */
        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            width: 100%;
            margin-top: 60px;
        }

        body.dark-mode .main-content {
            background-color: var(--dark-bg);
        }

        /* Content Area */
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

        /* Page Header */
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

        /* Responsive */
        @media(max-width:768px) {
            .main-content {
                padding: 1rem;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .reports-grid {
                grid-template-columns: 1fr;
            }

            .stat-grid {
                grid-template-columns: 1fr;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }

            .filter-actions {
                flex-direction: column;
            }

            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }

        @media(max-width:480px) {
            .main-content {
                padding: 0.8rem;
            }

            .filters-container {
                margin: 1rem;
                padding: 1rem;
            }

            .table-container {
                padding: 0 1rem 1rem;
            }
        }

        /* Print Styles - Remove header when printing */
        @media print {

            .page-header,
            .filters-container,
            .report-card-actions,
            .theme-toggle-container,
            .btn,
            .reports-grid {
                display: none !important;
            }

            .main-content {
                padding: 0 !important;
                background: white !important;
            }

            .content-area {
                box-shadow: none !important;
                border-radius: 0 !important;
                background: white !important;
            }

            .report-card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }

            body {
                background: white !important;
                color: black !important;
            }

            .data-table {
                font-size: 10px;
                box-shadow: none !important;
            }

            .data-table th {
                background: #f0f0f0 !important;
                color: black !important;
                border-bottom: 2px solid #000 !important;
            }

            .stat-item {
                background: #fff !important;
                border: 1px solid #000 !important;
            }
            
            /* Enhanced Print Layout for Slate Freight */
            .print-header-layout {
                margin-bottom: 30px;
            }
            .print-company {
                font-size: 28pt;
                color: #2c3e50 !important;
                border-bottom: 3px solid #e74a3b; /* Brand color accent */
                display: inline-block;
                padding-bottom: 5px;
            }
            .print-date {
                font-size: 10pt;
                margin-top: 5px;
            }
            .data-table td, .data-table th {
                padding: 8px !important;
            }
        }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <!-- Main Content -->
    <div class="main-content">
        <!-- Print Header (Visible only in print) -->
        <div class="print-header-layout">
            <div class="print-company">Slate Freight</div>
            <div class="print-date">Generated: <?php echo date('F d, Y'); ?></div>
            <div class="print-title">Core Human Capital Summary</div>
        </div>

        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-chart-line"></i>
                    HR Reports
                </h1>
                <p class="page-subtitle">Employee data reporting and export from API</p>
            </div>

            <?php if (isset($api_error)): ?>
            <div class="api-error-banner">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong>API Connection Error:</strong> <?php echo htmlspecialchars($api_error); ?><br>
                    <small>Falling back to cached or sample data. Please check if the API endpoint is accessible.</small>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters Section -->
            <div class="filters-container">
                <div class="filters-header">
                    <h3 class="filters-title">
                        <i class="fas fa-filter"></i> Report Filters
                    </h3>
                </div>
                <form method="GET" action="" class="filters-form" id="filterForm">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department" id="departmentFilter">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"
                                    <?php echo $department_filter === $dept ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contract Type</label>
                        <select class="form-select" name="employment_type" id="employmentFilter">
                            <option value="">All Types</option>
                            <?php foreach ($contract_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $employment_type_filter === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Employee Status</label>
                        <select class="form-select" name="status" id="statusFilter">
                            <option value="">All Statuses</option>
                            <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-input" name="search" id="searchFilter"
                            placeholder="Name, position, or department..."
                            value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary" id="applyFilters">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Statistics Cards -->
            <div class="reports-grid">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Employee Overview</h3>
                    </div>
                    <div class="stat-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $overall_stats['total_employees']; ?></div>
                            <div class="stat-label">Total Employees</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $overall_stats['active_employees']; ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Salary Overview</h3>
                    </div>
                    <div class="stat-grid">
                        <div class="stat-item">
                            <div class="stat-value">₱<?php echo number_format($overall_stats['overall_avg_salary'], 0); ?></div>
                            <div class="stat-label">Average Salary</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">₱<?php echo number_format($overall_stats['total_payroll'], 2); ?></div>
                            <div class="stat-label">Total Payroll</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employee List -->
            <div class="table-container">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">
                            Employee List
                            <small>(<?php echo count($filtered_employees); ?> employees found)</small>
                        </h3>
                        <div class="report-card-actions">
                            <button class="btn btn-sm btn-primary" onclick="exportReport('excel')">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <button class="btn btn-sm btn-success" onclick="printAsPDF()">
                                <i class="fas fa-print"></i> Print as PDF
                            </button>
                        </div>
                    </div>
                    <table class="data-table" id="employeesTable">
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
                                        <td>
                                            <?php
                                            $contract = $employee['contract'] ?? '';
                                            $badge_class = '';
                                            // Mapping contract types to badge colors
                                            switch (strtolower($contract)) {
                                                case 'regular':
                                                case 'full-time':
                                                    $badge_class = 'badge-full-time';
                                                    break;
                                                case 'probationary':
                                                    $badge_class = 'badge-probation';
                                                    break;
                                                case 'contract':
                                                    $badge_class = 'badge-contract';
                                                    break;
                                                case 'part-time':
                                                case 'project-based':
                                                    $badge_class = 'badge-part-time';
                                                    break;
                                                default:
                                                    $badge_class = 'badge-secondary';
                                            }
                                            ?>
                                            <span class="employment-badge <?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($contract ?: 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $employee['status'] ?? '';
                                            $status_class = $status === 'Active' ? 'status-active' : 'status-inactive';
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($status ?: 'N/A'); ?>
                                            </span>
                                        </td>
                                        <td class="currency">
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
                                    <td colspan="8" style="text-align: center; padding: 2rem;">
                                        No employees found matching your filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Export Functions for Excel and CSV
        function exportReport(type) {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);

            window.open(`?export=${type}&${params.toString()}`, '_blank');
        }

        // Print as PDF function
        function printAsPDF() {
            // Open the dedicated print page in a new window/tab
            // Pass current filters
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            window.open('print-report.php?' + params.toString(), '_blank');
        }

        // Add loading state for filters
        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                const submitBtn = document.getElementById('applyFilters');
                if (submitBtn) {
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Applying...';
                    submitBtn.disabled = true;

                    // Re-enable button after 2 seconds in case of error
                    setTimeout(() => {
                        submitBtn.innerHTML = '<i class="fas fa-filter"></i> Apply Filters';
                        submitBtn.disabled = false;
                    }, 2000);
                }
            });
        }

        // Auto-submit form when filters change (optional)
        const autoSubmitFilters = document.querySelectorAll('#departmentFilter, #employmentFilter, #statusFilter');
        autoSubmitFilters.forEach(filter => {
            filter.addEventListener('change', function() {
                filterForm.submit();
            });
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey)) {
                switch (e.key) {
                    case 'e':
                        e.preventDefault();
                        exportReport('excel');
                        break;
                    case 'p':
                        e.preventDefault();
                        printAsPDF();
                        break;
                    case 's':
                        e.preventDefault();
                        exportReport('csv');
                        break;
                }
            }
        });

        // Show keyboard shortcuts help
        console.log('Keyboard shortcuts: Ctrl+E (Excel), Ctrl+P (Print as PDF), Ctrl+S (CSV)');
    </script>
</body>

</html>
<?php ob_end_flush(); ?>