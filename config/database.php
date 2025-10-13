<?php
$host = 'localhost';
$dbname = 'u372764244_warehousebbb';
$username = 'u372764244_bbrilliant';
$password = 'Bbb@warehouse123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Set timezone
date_default_timezone_set('Asia/Phnom_Penh');
?>