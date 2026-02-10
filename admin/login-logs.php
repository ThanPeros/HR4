<?php
// admin/login-logs.php
include '../config/db.php';
include '../includes/sidebar.php';

// Check for HR Manager access (optional, but recommended for viewing logs)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hr_manager') {
    // Optionally redirect or show restricted message
    // header('Location: ../dashboard/index.php');
    // exit;
}

// Logic to read logs
$logFile = '../logs/login_attempts.log';
$logs = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        $lines = array_reverse($lines); // Show newest first
        foreach ($lines as $line) {
            // Pattern to match: Date - IP: ... - Username: ... - Status: ... - Details: ...
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - IP: (.*?) - Username: (.*?) - Status: (.*?) - Details: (.*)$/', $line, $matches)) {
                $logs[] = [
                    'time' => $matches[1],
                    'ip' => $matches[2],
                    'username' => $matches[3],
                    'status' => $matches[4],
                    'details' => $matches[5]
                ];
            }
        }
    }
}

// Helper for status badge
function getStatusBadge($status) {
    if (strpos(strtoupper($status), 'SUCCESS') !== false) {
        return '<span class="badge bg-success">Success</span>';
    } elseif (strpos(strtoupper($status), 'FAILED') !== false) {
        return '<span class="badge bg-danger">Failed</span>';
    } else {
        return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Logs | Slate Freight</title>
    <!-- Bootstrap 5 & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --success: #1cc88a;
            --danger: #e74a3b;
            --dark: #5a5c69;
            --light: #f8f9fc;
            --card-bg: #ffffff;
            --body-bg: #f3f4f6;
            --text-main: #2c3e50;
            --border-color: #e3e6f0;
        }

        body.dark-mode {
            --card-bg: #2d3748;
            --body-bg: #1a202c;
            --text-main: #f7fafc;
            --border-color: #4a5568;
        }

        body { 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            background-color: var(--body-bg); 
            color: var(--text-main); 
            transition: background-color 0.3s; 
        }
        
        .main-content { 
            padding: 2rem; 
            margin-top: 60px; 
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            background-color: var(--card-bg);
        }

        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
        }
        
        .table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            color: var(--secondary);
        }
        
        .table td {
            vertical-align: middle;
            font-size: 0.9rem;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(78, 115, 223, 0.05);
        }

        /* Color classes for dark mode support */
        .text-main { color: var(--text-main) !important; }
        
        /* DataTable styling tweaks if needed */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info, 
        .dataTables_wrapper .dataTables_paginate {
             color: var(--text-main) !important;
             margin-bottom: 1rem;
        }
    </style>
</head>
<body class="<?php echo isset($_SESSION['theme']) && $_SESSION['theme'] === 'dark' ? 'dark-mode' : ''; ?>">

<div class="main-content">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 font-weight-bold text-main"><i class="fas fa-history me-2"></i>System Login Logs</h1>
                <p class="text-muted mb-0">Monitor user access and security events</p>
            </div>
            <div>
                 <button class="btn btn-primary shadow-sm btn-sm" onclick="location.reload();">
                    <i class="fas fa-sync-alt fa-sm text-white-50 me-1"></i> Refresh
                </button>
            </div>
        </div>

        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Recent Login Activity</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="dataTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>IP Address</th>
                                <th>Status</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No login attempts recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr class="<?php echo strpos($log['status'], 'FAILED') !== false ? 'table-danger' : ''; ?>" style="<?php echo strpos($log['status'], 'FAILED') !== false ? '--bs-table-bg-type: rgba(231, 74, 59, 0.05);' : ''; ?>">
                                        <td><?php echo date('M d, Y h:i A', strtotime($log['time'])); ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($log['username']); ?></td>
                                        <td class="font-monospace small"><?php echo htmlspecialchars($log['ip']); ?></td>
                                        <td><?php echo getStatusBadge($log['status']); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($log['details']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Optional: Add DataTables for better sorting/filtering if needed later -->
</body>
</html>
