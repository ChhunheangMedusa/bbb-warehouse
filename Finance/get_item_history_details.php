<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$id = (int)$_GET['id'];

$stmt = $pdo->prepare("SELECT 
    si.*,
    c.name as category_name,
    l.name as location_name
FROM 
    stock_in_history si
LEFT JOIN 
    categories c ON si.category_id = c.id
JOIN 
    locations l ON si.location_id = l.id
WHERE 
    si.id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

// Get images from the original item
$stmt = $pdo->prepare("SELECT * FROM item_images WHERE item_id = ?");
$stmt->execute([$item['item_id']]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'item' => $item,
    'images' => $images
]);
?>