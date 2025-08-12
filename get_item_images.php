<?php
require_once 'config/database.php';

if(isset($_GET['id'])) {
    $item_id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT id FROM item_images WHERE item_id = ? ORDER BY id");
    $stmt->execute([$item_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($images);
    exit;
}

echo json_encode([]);
?>