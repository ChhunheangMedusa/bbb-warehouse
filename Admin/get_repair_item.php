<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT r.*, fl.name as from_location_name, tl.name as to_location_name 
                          FROM repair_items r
                          JOIN locations fl ON r.from_location_id = fl.id
                          JOIN locations tl ON r.to_location_id = tl.id
                          WHERE r.id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($item ?: null);
}
?>