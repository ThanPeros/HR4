<?php
require_once 'config/db.php';
$stmt = $pdo->query('DESCRIBE employees');
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Columns in employees table:\n";
foreach ($columns as $col) {
    echo "Field: " . $col['Field'] . " | Type: " . $col['Type'] . " | Null: " . $col['Null'] . " | Default: " . $col['Default'] . "\n";
}
?>
