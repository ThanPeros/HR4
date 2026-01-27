<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

require "../config/db.php";

// OPTIONAL filters
$employee_id = $_GET['employee_id'] ?? null;
$date        = $_GET['date'] ?? null;

$sql = "SELECT 
            id,
            employee_id,
            date,
            time_in,
            time_out,
            status
        FROM attendance
        WHERE 1=1";

if ($employee_id) {
    $sql .= " AND employee_id = '" . $conn->real_escape_string($employee_id) . "'";
}

if ($date) {
    $sql .= " AND date = '" . $conn->real_escape_string($date) . "'";
}

$sql .= " ORDER BY date DESC";

$result = $conn->query($sql);

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    "success" => true,
    "count"   => count($data),
    "data"    => $data
]);
