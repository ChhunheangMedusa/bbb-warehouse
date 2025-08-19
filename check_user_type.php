<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

header('Content-Type: application/json');

if (isset($_GET['username'])) {
    $username = sanitizeInput($_GET['username']);
    
    $stmt = $pdo->prepare("SELECT user_type FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode(['user_type' => $user['user_type']]);
    } else {
        echo json_encode(['error' => 'User not found']);
    }
} else {
    echo json_encode(['error' => 'Username not provided']);
}
?>