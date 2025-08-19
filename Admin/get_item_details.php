<?php
require_once '../config/database.php';

$item_id = (int)$_GET['id'];

header('Content-Type: application/json');

try {
    // Get item details with category name
    $stmt = $pdo->prepare("SELECT i.*, l.name as location_name, c.name as category_name 
                          FROM items i 
                          JOIN locations l ON i.location_id = l.id 
                          LEFT JOIN categories c ON i.category_id = c.id
                          WHERE i.id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get images
    $stmt = $pdo->prepare("SELECT id FROM item_images WHERE item_id = ? ORDER BY id");
    $stmt->execute([$item_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'item' => $item,
        'images' => $images
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}