<?php
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'Item ID not provided']);
    exit;
}

$itemId = (int)$_GET['id'];

try {
    // Get item details
    $stmt = $pdo->prepare("
        SELECT i.*, l.name as location_name, c.name as category_name, 
               DATE_FORMAT(i.date, '%d/%m/%Y') as date_formatted
        FROM items i
        JOIN locations l ON i.location_id = l.id
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['error' => 'Item not found']);
        exit;
    }

    // Get item images
    $stmt = $pdo->prepare("SELECT id FROM item_images WHERE item_id = ? ORDER BY id");
    $stmt->execute([$itemId]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'item_code' => $item['item_code'],
        'category_name' => $item['category_name'],
        'invoice_no' => $item['invoice_no'],
        'date_formatted' => $item['date_formatted'],
        'name' => $item['name'],
        'quantity' => $item['quantity'],
        'unit' => $item['size'],
        'location_name' => $item['location_name'],
        'remark' => $item['remark'],
        'images' => $images
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}