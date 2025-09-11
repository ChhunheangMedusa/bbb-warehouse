<?php
require_once '../config/database.php';

header('Content-Type: application/json');

$email = $_POST['email'] ?? '';
$current_id = $_POST['current_id'] ?? null;

try {
    if ($current_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $current_id]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
    }
    
    $count = $stmt->fetchColumn();
    echo json_encode(['exists' => $count > 0]);
} catch (PDOException $e) {
    echo json_encode(['exists' => false]);
}