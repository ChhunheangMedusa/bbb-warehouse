<?php
require_once '../config/database.php';

$id = (int)$_GET['id'];

// First get the item_id from history
$stmt = $pdo->prepare("SELECT item_id FROM stock_in_history WHERE id = ?");
$stmt->execute([$id]);
$item_id = $stmt->fetchColumn();

// Then get images for that item
$stmt = $pdo->prepare("SELECT * FROM item_images WHERE item_id = ?");
$stmt->execute([$item_id]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($images);
?>