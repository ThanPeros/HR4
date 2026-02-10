<?php
require_once '../config/db.php';
global $pdo;
$stmt = $pdo->query("SELECT count(*) as c FROM employees");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
file_put_contents('count.txt', "Count: " . $row['c']);
