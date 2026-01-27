<?php
// config/db.php

$host = 'localhost';
$dbname = 'dummy_hr4';
$username = 'root';
$password = '';

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Create MySQLi connection
    $conn = new mysqli($host, $username, $password, $dbname);

    // Check MySQLi connection
    if ($conn->connect_error) {
        throw new Exception("MySQLi connection failed: " . $conn->connect_error);
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Make $pdo globally available
global $pdo;

// Return the connection for use in other files
return $conn;
