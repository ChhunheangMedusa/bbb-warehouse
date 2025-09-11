<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

try {
    $username = isset($_POST['username']) ? sanitizeInput($_POST['username']) : '';
    $current_id = isset($_POST['current_id']) ? (int)$_POST['current_id'] : null;
    
    if (empty($username)) {
        echo json_encode(['exists' => false]);
        exit;
    }
    
    $query = "SELECT COUNT(*) as count FROM users WHERE username = ?";
    $params = [$username];
    
    if ($current_id) {
        $query .= " AND id != ?";
        $params[] = $current_id;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['exists' => $result['count'] > 0]);
} catch (PDOException $e) {
    echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
}